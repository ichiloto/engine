<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Replaceable atlas layout treatment alongside the shared palette and frames. */
final readonly class MapAtlasTheme
{
    public const array FRAME_ROLES = ['quiet', 'panel'];
    public const int MIN_EXTENT = 96;
    public const int MAX_EXTENT = 768;
    public const int MAX_MARGIN = 64;
    public const int MIN_MARKER_SIZE = 4;
    public const int MAX_MARKER_SIZE = 24;
    public function __construct(
        public int $compactWidth = 256,
        public int $compactHeight = 192,
        public int $largeWidth = 400,
        public int $largeHeight = 300,
        public int $margin = 16,
        public int $markerSize = 10,
        public string $frameRole = 'quiet',
        public float $cornerOpacity = 0.75,
    ) {
        if (!is_finite($cornerOpacity) || $cornerOpacity<0 || $cornerOpacity>1) { throw new InvalidArgumentException('Atlas corner opacity must be finite and within 0..1.'); }
        foreach ([$compactWidth, $compactHeight, $largeWidth, $largeHeight] as $extent) {
            if ($extent < self::MIN_EXTENT || $extent > self::MAX_EXTENT) { throw new InvalidArgumentException('Atlas corner dimensions must be within 96..768 logical pixels.'); }
        }
        if ($largeWidth < $compactWidth || $largeHeight < $compactHeight || $margin < 0 || $margin > self::MAX_MARGIN
            || $markerSize < self::MIN_MARKER_SIZE || $markerSize > self::MAX_MARKER_SIZE || !in_array($frameRole, self::FRAME_ROLES, true)) {
            throw new InvalidArgumentException('Atlas requires increasing corner sizes, margin 0..64, markers 4..24 and a quiet or panel frame.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['compactWidth', 'compactHeight', 'largeWidth', 'largeHeight', 'margin', 'markerSize', 'frameRole', 'cornerOpacity']) !== []) {
            throw new InvalidArgumentException('Unsupported atlas theme field.');
        }
        foreach ($data as $key => $value) {
            if (match($key) { 'frameRole'=>!is_string($value), 'cornerOpacity'=>!is_int($value) && !is_float($value), default=>!is_int($value) }) {
                throw new InvalidArgumentException('Atlas dimensions require integers; frameRole a string; cornerOpacity a number.');
            }
        }
        return new self(...$data);
    }
}
