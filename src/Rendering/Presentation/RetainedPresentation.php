<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Util\Debug;
use OverflowException;
use Closure;
use Throwable;

/** Sender-owned desired state; delivery generations never depend on frame cadence. */
final class RetainedPresentation
{
    public const int CHUNK_BYTES = 32768;
    public const int MAX_MESSAGE_BYTES = 4194304;
    public const int ENVELOPE_RESERVE_BYTES = 4096;
    public const float ACK_TIMEOUT_SECONDS = 2.0;
    public const int SEND_BUDGET_BYTES = 1048576;
    public const int MAX_SEND_PACKETS = 32;
    public const int MAX_IN_FLIGHT = 32;
    public const int MAX_PENDING_WRITE_BYTES = 1048576;
    private int $generation = 0;
    private int $frame = 0;
    private bool $reset = true;
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $values = [];
    /** @var array<string, array<int, list<array<string, mixed>>>> */
    private array $textRows = [];
    private ?PresentationWorld $world = null;
    private ?array $viewport = null;
    /** @var list<array<string, mixed>> */
    private array $operations = [];
    private bool $viewportChanged = false;
    private int $invalidation = 0;
    private int $acknowledgedGeneration = 0;
    private int $progressGeneration = 0;
    private ?float $pendingSince = null;
    private ?RetainedUpload $upload = null;
    private ?RetainedUpload $reconcile = null;
    private bool $coalesced = false;
    private int $windowBase = 0;
    private int $pendingWriteBytes = 0;

    public function __construct(private readonly RendererClient $client, private readonly ?Closure $serviceTransport = null,
        private readonly ?Closure $clock = null) {}

    public function acknowledge(int $generation, bool $presented): void
    {
        if ($generation > $this->generation || $generation <= $this->windowBase || $generation < $this->progressGeneration) { return; }
        if ($generation > $this->progressGeneration) {
            $this->progressGeneration = $generation;
            $this->pendingSince = $this->getTime();
        }
        if ($presented) { $this->acknowledgedGeneration = max($this->acknowledgedGeneration, $generation); }
        if ($this->acknowledgedGeneration === $this->generation) { $this->pendingSince = null; }
    }

    /**
     * A world's upload for this session: tile covers only for a renderer that negotiated them.
     *
     * @return list<array<string, mixed>>
     */
    private function getWorldOperations(PresentationWorld $world): array
    {
        return $world->getOperations($this->client->supports(RendererSessionConfig::TILE_COVERS));
    }

    private function getTime(): float { return $this->clock === null ? hrtime(true) / 1_000_000_000 : ($this->clock)(); }

    /** Session loss, resize and rejected deltas all use the same reset transaction. */
    public function invalidate(bool $newSession = false, ?int $expectedGeneration = null): void
    {
        $this->reset = true;
        $this->invalidation++;
        $this->pendingSince = null;
        $this->upload = $this->reconcile = null;
        $this->coalesced = false;
        $this->generation = max($this->generation, $expectedGeneration ?? 0);
        $this->windowBase = $this->generation;
        if ($newSession) {
            $this->generation = $this->acknowledgedGeneration = $this->progressGeneration = 0;
            $this->windowBase = $this->pendingWriteBytes = 0;
        }
    }

    /** Pending staged/coalesced work, not the final display acknowledgement. */
    public function hasPendingUpload(): bool { return $this->reset || $this->upload !== null || $this->reconcile !== null; }

    /** @param list<PresentationSprite> $sprites */
    public function present(ConsolePresentationChanges|ConsolePresentationSnapshot $text, array $sprites,
        ?PresentationViewport $viewport, ?PresentationWorld $world = null): bool
    {
        $this->operations = [];
        foreach (['canvas', 'canvas_image', 'canvas_indicator', 'canvas_text', 'canvas_composite'] as $kind) {
            $this->replaceValues($kind, []);
        }
        if ($world !== $this->world) {
            if ($this->world !== null) { $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $this->world->id]; }
            $this->world = $world;
            if ($world !== null) { array_push($this->operations, ...$this->getWorldOperations($world)); }
        }
        $this->updateText($text);
        $lift = $this->client->supports(RendererSessionConfig::SPRITE_LIFT);
        $this->replaceValues('sprite', array_map(static fn($sprite) => $sprite->toArray($lift), PresentationSprite::orderedList($sprites)));
        $this->setViewport($viewport?->toArray());
        return $this->flush();
    }

    public function presentCanvas(PresentationCanvas $canvas): bool
    {
        $this->operations = [];
        if ($this->world !== null) {
            $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $this->world->id];
            $this->world = null;
        }
        $this->replaceValues('text', []);
        $this->textRows = [];
        $this->replaceValues('sprite', []);
        $this->replaceValues('canvas', [['id' => 'canvas', 'width' => $canvas->width, 'height' => $canvas->height]]);
        foreach (['canvas_image' => $canvas->images, 'canvas_indicator' => $canvas->indicators,
            'canvas_text' => $canvas->textLayers, 'canvas_composite' => $canvas->composites] as $kind => $items) {
            $this->replaceValues($kind, array_map(static fn($item) => $item->toArray(), $items));
        }
        $this->setViewport(null);
        return $this->flush();
    }

    private function setViewport(?array $viewport): void
    {
        $this->viewportChanged = $this->viewport !== $viewport;
        $this->viewport = $viewport;
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceValues(string $kind, array $items): void
    {
        $next = [];
        foreach ($items as $order => $value) {
            $id = $value['id'];
            $value['order'] = $order;
            $next[$id] = $value;
            if (($this->values[$kind][$id] ?? null) !== $value) { $this->put($kind, $id, $value); }
        }
        foreach ($this->values[$kind] ?? [] as $id => $_) {
            if (!isset($next[$id])) { $this->operations[] = ['op' => 'remove', 'kind' => $kind, 'id' => (string)$id]; }
        }
        $this->values[$kind] = $next;
    }

    private function put(string $kind, string $id, array $value): void
    {
        $this->operations[] = ['op' => 'put', 'kind' => $kind, 'id' => $id, 'value' => $value];
    }

    private function updateText(ConsolePresentationChanges|ConsolePresentationSnapshot $text): void
    {
        if ($text instanceof ConsolePresentationSnapshot) {
            $ids = $layers = [];
            foreach ($text->textLayers as $layer) {
                $ids[] = $layer->id;
                $rows = [];
                foreach ($layer->runs as $run) { $rows[$run->row][] = $run; }
                foreach ($this->textRows[$layer->id] ?? [] as $row => $_) { $rows[$row] ??= []; }
                $layers[] = ['id' => $layer->id, 'layer' => $layer->layer,
                    'rows' => array_map(static fn($row, $runs) => ['row' => $row, 'runs' => $runs], array_keys($rows), $rows)];
            }
            $removed = array_diff(array_keys($this->textRows), $ids);
        } else {
            $layers = $text->layers;
            $ids = $text->order;
            $removed = $text->removedIds;
            if ($text->reset) {
                $removed = array_diff(array_keys($this->textRows), array_column($layers, 'id'));
                // A Console reset is a replacement, including formerly populated rows.
                foreach ($layers as &$layer) {
                    $presentRows = array_column($layer['rows'], 'row');
                    foreach ($this->textRows[$layer['id']] ?? [] as $row => $_) {
                        if (!in_array($row, $presentRows, true)) { $layer['rows'][] = ['row' => $row, 'runs' => []]; }
                    }
                }
                unset($layer);
            }
        }
        foreach ($removed as $id) {
            $this->operations[] = ['op' => 'remove', 'kind' => 'text', 'id' => (string)$id];
            unset($this->textRows[$id], $this->values['text'][$id]);
        }
        $orders = $ids === null ? [] : array_flip($ids);
        foreach ($layers as $layer) {
            $id = $layer['id'];
            $old = $this->values['text'][$id] ?? null;
            $metadata = ['id' => $id, 'layer' => $layer['layer'], 'order' => $orders[$id] ?? ($old['order'] ?? 0)];
            $changed = [];
            foreach ($layer['rows'] as $row) {
                $runs = array_map(static fn($run) => $run->toArray(), $row['runs']);
                if (($this->textRows[$id][$row['row']] ?? []) !== $runs) {
                    $changed[] = ['row' => $row['row'], 'runs' => $runs];
                }
                if ($runs === []) { unset($this->textRows[$id][$row['row']]); }
                else { $this->textRows[$id][$row['row']] = $runs; }
            }
            $this->textRows[$id] ??= [];
            $this->values['text'][$id] = $metadata;
            if ($old !== $metadata) { $this->putText($id); }
            elseif ($changed !== []) { $this->operations[] = ['op' => 'textRows', 'id' => $id, 'rows' => $changed]; }
        }
        foreach ($orders as $id => $order) {
            if (isset($this->values['text'][$id]) && $this->values['text'][$id]['order'] !== $order) {
                $this->values['text'][$id]['order'] = $order;
                $this->putText((string)$id);
            }
        }
    }

    private function putText(string $id): void
    {
        $runs = [];
        foreach ($this->textRows[$id] ?? [] as $row) { array_push($runs, ...$row); }
        $this->put('text', $id, [...$this->values['text'][$id], 'runs' => $runs]);
    }

    private function flush(): bool
    {
        $this->observeWriteProgress();
        if ($this->pendingSince !== null && $this->getTime() - $this->pendingSince >= self::ACK_TIMEOUT_SECONDS) {
            Debug::warn('Renderer presentation acknowledgement timed out; retained state will be resynchronized.');
            $this->invalidate();
        }
        if ($this->upload !== null) {
            $this->coalesced = $this->coalesced || $this->operations !== [] || $this->viewportChanged;
        } elseif ($this->reconcile !== null) {
            $this->reconcileChanges($this->reconcile);
            $this->reconcile = null;
        }
        if ($this->upload === null && !$this->reset && $this->operations === [] && !$this->viewportChanged) { return false; }
        if ($this->upload === null && $this->reset) {
            $this->operations = $this->world === null ? [] : $this->getWorldOperations($this->world);
            foreach ($this->values as $kind => $items) {
                foreach ($items as $id => $value) {
                    if ($kind === 'text') { $this->putText((string)$id); }
                    else { $this->put($kind, (string)$id, $value); }
                }
            }
        }
        $sent = 0;
        $packets = 0;
        $passes = 0;
        try {
            if ($this->upload === null) {
                if ($this->frame === PHP_INT_MAX) { throw new OverflowException('Retained presentation sequence exhausted.'); }
                $this->upload = new RetainedUpload($this->operations, $this->frame + 1, $this->reset,
                    $this->reset || $this->viewportChanged, $this->viewport, $this->values, $this->textRows, $this->world);
                $this->reset = false;
                $this->coalesced = false;
            }
            $invalidation = $this->invalidation;
            while ($this->upload !== null && $sent < self::SEND_BUDGET_BYTES && $packets < self::MAX_SEND_PACKETS
                && $passes < self::MAX_SEND_PACKETS) {
                if ($this->generation - max($this->windowBase, $this->progressGeneration) >= self::MAX_IN_FLIGHT) {
                    $progress = $this->progressGeneration;
                    $this->serviceTransport(); $passes++;
                    if ($this->invalidation !== $invalidation || $this->progressGeneration === $progress) { break; }
                    continue;
                }
                if ($this->generation === PHP_INT_MAX) { throw new OverflowException('Retained presentation sequence exhausted.'); }
                $message = $this->upload->getMessage($this->generation);
                $bytes = strlen($message->encode());
                // A bounded pipeline feeds fast readers without building a whole-map byte backlog.
                // An indivisible larger record is allowed only when the queue is empty.
                if ($this->client->getPendingWriteBytes() + $bytes > max(self::MAX_PENDING_WRITE_BYTES, $bytes)) {
                    $before = $this->client->getPendingWriteBytes();
                    $this->serviceTransport(); $passes++;
                    if ($this->invalidation !== $invalidation || $this->pendingWriteBytes >= $before) { break; }
                    continue;
                }
                if (!$this->client->trySend($message)) {
                    $before = $this->client->getPendingWriteBytes();
                    $this->serviceTransport(); $passes++;
                    if ($this->invalidation !== $invalidation || $this->pendingWriteBytes >= $before) { break; }
                    continue;
                }
                $sent += $bytes;
                $packets++;
                $this->generation++;
                $this->frame = $this->upload->frame;
                $this->pendingSince ??= $this->getTime();
                $this->pendingWriteBytes = $this->client->getPendingWriteBytes();
                if ($this->upload->advance()) {
                    if ($this->coalesced) { $this->reconcile = $this->upload; }
                    $this->upload = null;
                    LatencyTrace::record('presentation.frame.queued', ['frame' => $this->frame, 'generation' => $this->generation]);
                    break;
                }
                $this->serviceTransport(); $passes++;
                if ($this->invalidation !== $invalidation) { break; }
            }
        } catch (Throwable $error) {
            $this->invalidate();
            throw $error;
        }
        return $sent > 0;
    }

    private function serviceTransport(): void
    {
        if ($this->serviceTransport !== null) { ($this->serviceTransport)(); }
        else { $this->client->pump(); }
        $this->observeWriteProgress();
    }

    private function observeWriteProgress(): void
    {
        $pending = $this->client->getPendingWriteBytes();
        if ($pending < $this->pendingWriteBytes && $this->pendingSince !== null) {
            $this->pendingSince = $this->getTime();
        }
        $this->pendingWriteBytes = $pending;
    }

    /** Only used after changes were coalesced during a multi-tick transaction. */
    private function reconcileChanges(RetainedUpload $baseline): void
    {
        $this->operations = [];
        if ($this->world !== $baseline->world) {
            if ($baseline->world !== null) { $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $baseline->world->id]; }
            if ($this->world !== null) { array_push($this->operations, ...$this->getWorldOperations($this->world)); }
        }
        foreach (array_unique([...array_keys($this->values), ...array_keys($baseline->values)]) as $kind) {
            foreach ($this->values[$kind] ?? [] as $id => $value) {
                if (($baseline->values[$kind][$id] ?? null) !== $value) {
                    if ($kind === 'text') { $this->putText((string)$id); }
                    else { $this->put($kind, (string)$id, $value); }
                } elseif ($kind === 'text') {
                    $before = $baseline->textRows[$id] ?? [];
                    $after = $this->textRows[$id] ?? [];
                    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $row) {
                        if (($before[$row] ?? []) !== ($after[$row] ?? [])) {
                            $this->operations[] = ['op' => 'textRows', 'id' => (string)$id,
                                'rows' => [['row' => $row, 'runs' => $after[$row] ?? []]]];
                        }
                    }
                }
            }
            foreach ($baseline->values[$kind] ?? [] as $id => $_) {
                if (!isset($this->values[$kind][$id])) { $this->operations[] = ['op' => 'remove', 'kind' => $kind, 'id' => (string)$id]; }
            }
        }
        $this->viewportChanged = $this->viewport !== $baseline->viewport;
    }
}
