<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Util\Debug;
use OverflowException;
use Closure;
use Throwable;
use LogicException;

/**
 * Sender-owned desired state; delivery generations never depend on frame cadence.
 *
 * Delivery is bounded by whole frames: while the renderer still owes acknowledgements for
 * MAX_FRAMES_IN_FLIGHT frames, newer changes stay desired state and the next frame carries only
 * their net difference from the last delivered one. Superseded intermediate frames are never
 * queued, so the frame answering an input never waits behind stale ones.
 */
final class RetainedPresentation
{
    public const int CHUNK_BYTES = 32768;
    public const int MAX_MESSAGE_BYTES = 4194304;
    public const int ENVELOPE_RESERVE_BYTES = 4096;
    public const float ACK_TIMEOUT_SECONDS = 2.0;
    public const int SEND_BUDGET_BYTES = 1048576;
    public const int MAX_SEND_PACKETS = 32;
    public const int MAX_IN_FLIGHT = 32;
    public const int MAX_FRAMES_IN_FLIGHT = 2;
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
    private ?PresentationCanvas $canvas = null;
    private bool $canvasIsOverlay = true;
    /** @var list<PresentationWorldCanvas> */
    private array $worldCanvases = [];
    private readonly object $frameOwner;
    /** @var list<array<string, mixed>|RetainedWorldUpload> A queued world is built as it is sent. */
    private array $operations = [];
    private bool $viewportChanged = false;
    private int $invalidation = 0;
    private int $acknowledgedGeneration = 0;
    private int $progressGeneration = 0;
    private ?float $pendingSince = null;
    private ?RetainedUpload $upload = null;
    private ?RetainedUpload $reconcile = null;
    /** The last fully queued transaction: what the renderer shows once it drains its input. */
    private ?RetainedUpload $delivered = null;
    /** @var list<int> Final generations of fully queued frames the renderer has not consumed yet. */
    private array $framesInFlight = [];
    /** @var array<int, PresentationWorld|null> The world of each fully queued frame, by final generation, until it is shown. */
    private array $queuedWorlds = [];
    /**
     * The world of the newest frame the renderer acknowledged as shown: a world map streams over many
     * game ticks, so a scene can know when its arrival is actually on screen. Null before any frame
     * or after the session restarts.
     */
    public private(set) ?PresentationWorld $presentedWorld = null;
    private bool $coalesced = false;
    private int $windowBase = 0;
    private int $pendingWriteBytes = 0;
    private bool $warnedUnsupportedPivot = false;

    public function __construct(private readonly RendererClient $client, private readonly ?Closure $serviceTransport = null,
        private readonly ?Closure $clock = null) { $this->frameOwner = new \stdClass(); }

    public function captureFrame(): RetainedFrame
    {
        return new RetainedFrame($this->frameOwner, $this->values, $this->textRows, $this->world,
            $this->viewport, $this->canvas, $this->canvasIsOverlay, $this->worldCanvases);
    }

    /** Prepare the incoming composition without sending an uncovered frame. */
    public function prepareFrame(ConsolePresentationChanges|ConsolePresentationSnapshot $text, array $sprites,
        ?PresentationViewport $viewport, ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null,
        array $worldCanvases = []): RetainedFrame
    {
        return $this->prepareRetainedFrame(fn() => $this->updateFrame($text, $sprites, $viewport, $world, $canvasOverlay, $worldCanvases));
    }

    public function prepareCanvas(PresentationCanvas $canvas, ?PresentationWorld $world = null): RetainedFrame
    {
        return $this->prepareRetainedFrame(fn() => $this->updateFullCanvas($canvas, $world));
    }

    private function prepareRetainedFrame(Closure $prepare): RetainedFrame
    {
        $previous = $this->captureFrame();
        $operations = $this->operations;
        $viewportChanged = $this->viewportChanged;
        try {
            $prepare();
            return $this->captureFrame();
        } finally {
            $this->values = $previous->values;
            $this->textRows = $previous->textRows;
            $this->world = $previous->world;
            $this->viewport = $previous->viewport;
            $this->canvas = $previous->canvas;
            $this->canvasIsOverlay = $previous->canvasIsOverlay;
            $this->worldCanvases = $previous->worldCanvases;
            $this->operations = $operations;
            $this->viewportChanged = $viewportChanged;
        }
    }

    /** Reuses the retained world, viewport, text and sprites under one temporary screen overlay. */
    public function presentFrame(RetainedFrame $frame, ?PresentationCanvas $screenOverlay = null): bool
    {
        if ($frame->owner !== $this->frameOwner) { throw new LogicException('A retained frame belongs to its renderer session.'); }
        $canvas = $frame->canvas;
        if ($screenOverlay !== null) {
            if ($canvas !== null && ($canvas->width !== $screenOverlay->width || $canvas->height !== $screenOverlay->height)) {
                throw new LogicException('A screen overlay must match its retained composition.');
            }
            $canvas = PresentationCanvas::composeOverlay($canvas, $screenOverlay);
        }
        CanvasFrameBudget::assertCanvases([...($canvas === null ? [] : [$canvas]),
            ...array_map(static fn($group) => $group->canvas, $frame->worldCanvases)]);
        $canvas?->assertWorldReferences($frame->world);
        foreach ($frame->worldCanvases as $group) { $group->canvas->assertWorldReferences($frame->world); }
        $this->operations = [];
        if ($this->world !== $frame->world) {
            if ($this->world !== null) { $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $this->world->id]; }
            $this->world = $frame->world;
            if ($this->world !== null) { $this->operations[] = $this->getWorldUpload($this->world); }
        }
        foreach ($this->values['text'] ?? [] as $id => $_) {
            if (!isset($frame->values['text'][$id])) { $this->operations[] = ['op' => 'remove', 'kind' => 'text', 'id' => (string)$id]; }
        }
        $oldText = $this->values['text'] ?? [];
        $oldRows = $this->textRows;
        $this->values['text'] = $frame->values['text'] ?? [];
        $this->textRows = $frame->textRows;
        foreach ($this->values['text'] as $id => $value) {
            if (($oldText[$id] ?? null) !== $value || ($oldRows[$id] ?? []) !== ($this->textRows[$id] ?? [])) {
                $this->putText((string)$id);
            }
        }
        $this->replaceValues('sprite', array_values($frame->values['sprite'] ?? []), true);
        $this->replaceValues('world_canvas', array_values($frame->values['world_canvas'] ?? []), true);
        $this->worldCanvases = $frame->worldCanvases;
        $this->updateCanvas($canvas, $frame->canvasIsOverlay);
        $this->setViewport($frame->viewport);
        return $this->flush();
    }

    public function acknowledge(int $generation, bool $presented): void
    {
        if ($generation > $this->generation || $generation <= $this->windowBase || $generation < $this->progressGeneration) { return; }
        if ($generation > $this->progressGeneration) {
            $this->progressGeneration = $generation;
            $this->pendingSince = $this->getTime();
        }
        if ($presented) {
            $this->acknowledgedGeneration = max($this->acknowledgedGeneration, $generation);
            foreach ($this->queuedWorlds as $final => $world) {
                if ($final > $generation) { break; }
                $this->presentedWorld = $world;
                unset($this->queuedWorlds[$final]);
            }
        }
        $this->framesInFlight = array_values(array_filter($this->framesInFlight,
            fn(int $final): bool => $final > $this->progressGeneration));
        if ($this->acknowledgedGeneration === $this->generation) { $this->pendingSince = null; }
    }

    /** A world's upload for this session: tile covers and shadows only for a renderer that negotiated them. */
    private function getWorldUpload(PresentationWorld $world): RetainedWorldUpload
    {
        return new RetainedWorldUpload($world, $this->client->supports(RendererSessionConfig::TILE_COVERS),
            $this->client->supports(RendererSessionConfig::TILE_SHADOWS));
    }

    private function getTime(): float { return $this->clock === null ? hrtime(true) / 1_000_000_000 : ($this->clock)(); }

    /** Session loss, resize and rejected deltas all use the same reset transaction. */
    public function invalidate(bool $newSession = false, ?int $expectedGeneration = null): void
    {
        $this->reset = true;
        $this->invalidation++;
        $this->pendingSince = null;
        $this->upload = $this->reconcile = $this->delivered = null;
        $this->framesInFlight = [];
        $this->queuedWorlds = [];
        $this->coalesced = false;
        $this->generation = max($this->generation, $expectedGeneration ?? 0);
        $this->windowBase = $this->generation;
        if ($newSession) {
            $this->presentedWorld = null;
            $this->warnedUnsupportedPivot = false;
            $this->generation = $this->acknowledgedGeneration = $this->progressGeneration = 0;
            $this->windowBase = $this->pendingWriteBytes = 0;
        }
    }

    /** Pending staged/coalesced work, not the final display acknowledgement. */
    public function hasPendingUpload(): bool { return $this->reset || $this->upload !== null || $this->reconcile !== null; }

    /** @param list<PresentationSprite> $sprites */
    public function present(ConsolePresentationChanges|ConsolePresentationSnapshot $text, array $sprites,
        ?PresentationViewport $viewport, ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null,
        array $worldCanvases = []): bool
    {
        $this->updateFrame($text, $sprites, $viewport, $world, $canvasOverlay, $worldCanvases);
        return $this->flush();
    }

    private function updateFrame(ConsolePresentationChanges|ConsolePresentationSnapshot $text, array $sprites,
        ?PresentationViewport $viewport, ?PresentationWorld $world, ?PresentationCanvas $canvasOverlay, array $worldCanvases): void
    {
        $canvasOverlay?->assertWorldReferences($world);
        foreach ($worldCanvases as $group) { $group->canvas->assertWorldReferences($world); }
        $subjects = PresentationSubjectOrder::getOrderedSubjects($sprites, $worldCanvases);
        $worldCanvases = PresentationWorldCanvas::getOrderedList($worldCanvases);
        if ($worldCanvases !== [] && ($world === null || $viewport?->worldId !== $world->id)) {
            throw new \InvalidArgumentException('World canvases require their selected field world and viewport.');
        }
        $viewport?->assertMembers($text, $sprites, worldCanvases: $worldCanvases);
        CanvasFrameBudget::assertCanvases([...($canvasOverlay === null ? [] : [$canvasOverlay]),
            ...array_map(static fn($group) => $group->canvas, $worldCanvases)]);
        $this->operations = [];
        $this->updateCanvas($canvasOverlay, true);
        if ($world !== $this->world) {
            if ($this->world !== null) { $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $this->world->id]; }
            $this->world = $world;
            if ($world !== null) { $this->operations[] = $this->getWorldUpload($world); }
        }
        $this->updateText($text);
        $lift = $this->client->supports(RendererSessionConfig::SPRITE_LIFT);
        $turns = $this->client->supports(RendererSessionConfig::SPRITE_QUARTER_TURNS);
        $pivot = $this->client->supports(RendererSessionConfig::SPRITE_PIVOT);
        $bob = $this->client->supports(RendererSessionConfig::SPRITE_BOB);
        if (!$pivot && !$this->warnedUnsupportedPivot && array_any($sprites, static fn($sprite): bool =>
            $sprite->pivot !== null && ($sprite->pivot->x !== .5 || $sprite->pivot->y !== 1.0))) {
            Debug::warn('Renderer lacks sprite_pivot; field images retain bottom-center placement.');
            $this->warnedUnsupportedPivot = true;
        }
        // Sprites and field canvases share one order space.
        $keys = array_map(static fn($subject): string => ($subject instanceof PresentationSprite ? 'sprite:' : 'world_canvas:')
            . $subject->id, $subjects);
        $previous = [];
        foreach (['sprite', 'world_canvas'] as $kind) {
            foreach ($this->values[$kind] ?? [] as $id => $value) { $previous[$kind . ':' . $id] = $value['order']; }
        }
        $orders = RetainedOrder::getStableOrders($keys, $previous);
        $spriteValues = $groupValues = [];
        foreach ($subjects as $index => $subject) {
            $value = [...($subject instanceof PresentationSprite ? $subject->toArray($lift, $turns, $pivot, $bob)
                : $subject->toArray()), 'order' => $orders[$keys[$index]]];
            if ($subject instanceof PresentationSprite) { $spriteValues[] = $value; }
            else { $groupValues[] = $value; }
        }
        $this->replaceValues('sprite', $spriteValues, true);
        $this->replaceValues('world_canvas', $groupValues, true);
        $this->worldCanvases = $worldCanvases;
        $this->setViewport($viewport?->toArray());
    }

    public function presentCanvas(PresentationCanvas $canvas, ?PresentationWorld $world = null): bool
    {
        $this->updateFullCanvas($canvas, $world);
        return $this->flush();
    }

    private function updateFullCanvas(PresentationCanvas $canvas, ?PresentationWorld $world = null): void
    {
        $canvas->assertWorldReferences($world);
        CanvasFrameBudget::assertCanvases([$canvas]);
        $this->operations = [];
        if ($this->world !== $world) {
            if ($this->world !== null) { $this->operations[] = ['op' => 'remove', 'kind' => 'world', 'id' => $this->world->id]; }
            $this->world = $world;
            if ($world !== null) { $this->operations[] = $this->getWorldUpload($world); }
        }
        $this->replaceValues('text', []);
        $this->textRows = [];
        $this->replaceValues('sprite', []);
        $this->replaceValues('world_canvas', []);
        $this->worldCanvases = [];
        $this->updateCanvas($canvas, false);
        $this->setViewport(null);
    }

    private function updateCanvas(?PresentationCanvas $canvas, bool $overlay): void
    {
        if ($overlay && $canvas !== null && $canvas->viewportFit !== CanvasViewportFit::NATIVE) {
            throw new \InvalidArgumentException('Field canvas overlays inherit their viewport and cannot request window fitting.');
        }
        $this->canvas = $canvas;
        $this->canvasIsOverlay = $overlay;
        $this->replaceValues('canvas', $canvas === null ? [] : [['id' => 'canvas', 'width' => $canvas->width,
            'height' => $canvas->height, ...($overlay ? ['mode' => 'overlay'] : []),
            ...($canvas->viewportFit === CanvasViewportFit::NATIVE ? [] : ['viewportFit' => $canvas->viewportFit->value])]]);
        foreach (['canvas_image' => $canvas?->images ?? [], 'canvas_indicator' => $canvas?->indicators ?? [],
            'canvas_text' => $canvas?->textLayers ?? [], 'canvas_composite' => $canvas?->composites ?? [],
            'canvas_label' => $canvas?->labels ?? []] as $kind => $items) {
            $this->replaceValues($kind, array_map(static fn($item) => $item->toArray(), $items));
        }
    }

    private function setViewport(?array $viewport): void
    {
        $this->viewportChanged = $this->viewport !== $viewport;
        $this->viewport = $viewport;
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceValues(string $kind, array $items, bool $preserveOrder = false): void
    {
        $next = [];
        $orders = $preserveOrder ? [] : RetainedOrder::getStableOrders(array_values(array_unique(array_map(
            static fn(array $value): string => (string)$value['id'], $items))), array_map(
            static fn(array $value): int => $value['order'], $this->values[$kind] ?? []));
        foreach ($items as $value) {
            $id = $value['id'];
            $value['order'] = $preserveOrder ? $value['order'] : $orders[$id];
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
        $orders = $ids === null ? [] : RetainedOrder::getStableOrders(array_map(strval(...), array_values($ids)),
            array_map(static fn(array $value): int => $value['order'], $this->values['text'] ?? []));
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
        } elseif ($this->reconcile !== null && !$this->isHoldingFrames()) {
            $this->reconcileChanges($this->reconcile);
            $this->reconcile = null;
        }
        if ($this->upload === null && !$this->reset && $this->reconcile === null && $this->operations === []
            && !$this->viewportChanged) { return false; }
        if ($this->upload === null && !$this->reset && $this->isHoldingFrames()) {
            // Keep desired state only; the next frame reconciles against what the renderer will show.
            $this->reconcile ??= $this->delivered ?? throw new LogicException('Frames in flight require their delivered baseline.');
            LatencyTrace::record('presentation.frame.held', ['frames_in_flight' => count($this->framesInFlight)]);
            return false;
        }
        if ($this->upload === null && $this->reset) {
            $this->operations = $this->world === null ? [] : [$this->getWorldUpload($this->world)];
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
                    $this->framesInFlight[] = $this->generation;
                    $this->queuedWorlds[$this->generation] = $this->upload->world;
                    $this->delivered = $this->upload;
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

    private function isHoldingFrames(): bool { return count($this->framesInFlight) >= self::MAX_FRAMES_IN_FLIGHT; }

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
            if ($this->world !== null) { $this->operations[] = $this->getWorldUpload($this->world); }
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
