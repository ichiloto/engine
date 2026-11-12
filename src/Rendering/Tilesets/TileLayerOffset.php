<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/** A graphical translation in field cells, independent of physical occupancy. */
final readonly class TileLayerOffset
{
    public const string DATA_KEY = 'layerOffsets';
    public const float MAX_ABSOLUTE = 0.5;

    public function __construct(public float $x, public float $y)
    {
        foreach ([$x, $y] as $value) {
            if (!is_finite($value) || abs($value) > self::MAX_ABSOLUTE) {
                throw new InvalidArgumentException('Tile layer offsets must be finite numbers between -0.5 and 0.5 field cells.');
            }
        }
    }

    public static function fromArray(mixed $data, string $context): self
    {
        if (!is_array($data) || !array_is_list($data) || count($data) !== 2
            || (!is_int($data[0]) && !is_float($data[0])) || (!is_int($data[1]) && !is_float($data[1]))) {
            throw new InvalidArgumentException("{$context} must be two numbers, across and down.");
        }
        try {
            return new self((float)$data[0], (float)$data[1]);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("{$context}: " . $error->getMessage(), previous: $error);
        }
    }

    /** @return array{float, float} */
    public function toArray(): array
    {
        return [$this->x, $this->y];
    }

    /** @return array<string, array{float, float}> */
    public static function readAll(mixed $data, string $context): array
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException("{$context} must map tile layer names to offsets.");
        }
        $offsets = [];
        foreach ($data as $name => $offset) {
            if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1) {
                throw new InvalidArgumentException("{$context} requires valid tile layer names.");
            }
            $offsets[$name] = self::fromArray($offset, "{$context} '{$name}'")->toArray();
        }
        return $offsets;
    }
}
