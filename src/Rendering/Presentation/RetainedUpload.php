<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;
use Generator;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use OverflowException;

/**
 * A fixed atomic transaction and its desired-state baseline, independent of later game ticks.
 *
 * Its operations are split into transport chunks as they are sent: only the chunk being sent is
 * held, and a queued world ({@see RetainedWorldUpload}) is built one operation at a time, so a world
 * map's upload never holds all its per-cell operations at once. Chunks are the same either way.
 */
final class RetainedUpload
{
    /** @var Generator<int, array<string, mixed>> The operations not yet taken into a chunk. */
    private Generator $remaining;
    /** @var array{0: array<string, mixed>, 1: int}|null An operation taken that did not fit the previous chunk, and its size. */
    private ?array $carried = null;
    /** @var list<array<string, mixed>>|null The chunk being sent. */
    private ?array $chunk = null;
    /** @var list<array<string, mixed>> Operations collected for a chunk that may span multiple updates. */
    private array $buildingChunk = [];
    private int $buildingBytes = 0;
    private bool $isFinalChunk = false;
    private int $index = 0;

    /** @param list<array<string, mixed>|RetainedWorldUpload> $operations */
    public function __construct(
        array $operations,
        public readonly int $frame,
        private readonly bool $reset,
        private readonly bool $includeViewport,
        public readonly ?array $viewport,
        public readonly array $values,
        public readonly array $textRows,
        public readonly ?PresentationWorld $world,
    ) {
        // Removals go first, in their order, then everything else in its order. A receiver checks its
        // limits (text layers, labels) on every staged packet, so a transaction split across packets must
        // never hold what it removes and what it adds at once: each side fits, but both together may not.
        $isRemoval = static fn(array|RetainedWorldUpload $operation): bool => is_array($operation)
            && ($operation['op'] ?? null) === 'remove';
        $this->remaining = (static function (array $ordered): Generator {
            foreach ($ordered as $operation) {
                if ($operation instanceof RetainedWorldUpload) { yield from $operation; }
                else { yield $operation; }
            }
        })([...array_values(array_filter($operations, $isRemoval)),
            ...array_values(array_filter($operations, static fn(array|RetainedWorldUpload $operation): bool => ! $isRemoval($operation)))]);
    }

    public function getMessage(int $generation): RendererMessage
    {
        $this->prepareChunk();
        return new RendererMessage(RendererMessageType::FRAME, ['frame' => $this->frame,
            'baseGeneration' => $generation, 'generation' => $generation + 1,
            'reset' => $this->reset && $this->index === 0,
            'present' => $this->isFinalChunk, 'operations' => $this->chunk,
            ...($this->isFinalChunk && $this->includeViewport ? ['viewport' => $this->viewport] : [])],
            RendererProtocolVersion::V2);
    }

    /** Returns true only when the final packet has been queued. */
    public function advance(): bool
    {
        $this->index++;
        if ($this->isFinalChunk) {
            $this->chunk = [];
            return true;
        }
        $this->chunk = null;
        return false;
    }

    /**
     * Build the next packet up to its byte budget. A caller can yield between operations and resume
     * the same packet later. Each invocation processes at least one operation when work remains.
     *
     * @param (Closure(): bool)|null $shouldYield
     */
    public function prepareChunk(?Closure $shouldYield = null): bool
    {
        if ($this->chunk !== null) { return true; }
        while ($this->carried !== null || $this->remaining->valid()) {
            if ($this->carried !== null) {
                [$operation, $size] = $this->carried;
                $this->carried = null;
            } else {
                $operation = $this->remaining->current();
                $this->remaining->next();
                $size = strlen(json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($size > RetainedPresentation::MAX_MESSAGE_BYTES - RetainedPresentation::ENVELOPE_RESERVE_BYTES) {
                    throw new OverflowException('A retained operation exceeds the transport line budget.');
                }
            }
            if ($this->buildingChunk !== [] && $this->buildingBytes + $size > RetainedPresentation::CHUNK_BYTES) {
                $this->carried = [$operation, $size];
                break;
            }
            $this->buildingChunk[] = $operation;
            $this->buildingBytes += $size;
            if ($this->remaining->valid() && ($shouldYield?->__invoke() ?? false)) { return false; }
        }
        $this->chunk = $this->buildingChunk;
        $this->buildingChunk = [];
        $this->buildingBytes = 0;
        $this->isFinalChunk = $this->carried === null && !$this->remaining->valid();
        return true;
    }
}
