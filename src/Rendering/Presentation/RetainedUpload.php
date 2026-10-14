<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use OverflowException;

/** A fixed atomic transaction and its desired-state baseline, independent of later game ticks. */
final class RetainedUpload
{
    private array $chunks = [];
    private int $index = 0;

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
        $chunk = [];
        $bytes = 0;
        foreach ($operations as $operation) {
            $size = strlen(json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($size > RetainedPresentation::MAX_MESSAGE_BYTES - RetainedPresentation::ENVELOPE_RESERVE_BYTES) {
                throw new OverflowException('A retained operation exceeds the transport line budget.');
            }
            if ($chunk !== [] && $bytes + $size > RetainedPresentation::CHUNK_BYTES) {
                $this->chunks[] = $chunk;
                $chunk = [];
                $bytes = 0;
            }
            $chunk[] = $operation;
            $bytes += $size;
        }
        $this->chunks[] = $chunk;
    }

    public function getMessage(int $generation): RendererMessage
    {
        return new RendererMessage(RendererMessageType::FRAME, ['frame' => $this->frame,
            'baseGeneration' => $generation, 'generation' => $generation + 1,
            'reset' => $this->reset && $this->index === 0,
            'present' => $this->index === count($this->chunks) - 1, 'operations' => $this->chunks[$this->index],
            ...($this->index === count($this->chunks) - 1 && $this->includeViewport ? ['viewport' => $this->viewport] : [])],
            RendererProtocolVersion::V2);
    }

    /** Returns true only when the final packet has been queued. */
    public function advance(): bool { return ++$this->index === count($this->chunks); }
}
