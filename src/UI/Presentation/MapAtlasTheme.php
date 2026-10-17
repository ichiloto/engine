<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Replaceable atlas layout treatment alongside the shared palette and frames. */
final readonly class MapAtlasTheme
{
    public function __construct(
        public int $compactWidth = 256,
        public int $compactHeight = 192,
        public int $largeWidth = 400,
        public int $largeHeight = 300,
        public int $margin = 16,
        public int $markerSize = 10,
        public string $frameRole = 'quiet',
    ) {
        foreach ([$compactWidth, $compactHeight, $largeWidth, $largeHeight] as $extent) {
            if ($extent < 96 || $extent > 768) { throw new InvalidArgumentException('Atlas corner dimensions must be within 96..768 logical pixels.'); }
        }
        if ($largeWidth < $compactWidth || $largeHeight < $compactHeight || $margin < 0 || $margin > 64
            || $markerSize < 4 || $markerSize > 24 || !in_array($frameRole, ['quiet', 'panel'], true)) {
            throw new InvalidArgumentException('Atlas requires increasing corner sizes, margin 0..64, markers 4..24 and a quiet or panel frame.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['compactWidth', 'compactHeight', 'largeWidth', 'largeHeight', 'margin', 'markerSize', 'frameRole']) !== []) {
            throw new InvalidArgumentException('Unsupported atlas theme field.');
        }
        foreach ($data as $key => $value) {
            if ($key === 'frameRole' ? !is_string($value) : !is_int($value)) {
                throw new InvalidArgumentException('Atlas dimensions, margin and markerSize require integers; frameRole requires a string.');
            }
        }
        return new self(...$data);
    }
}
