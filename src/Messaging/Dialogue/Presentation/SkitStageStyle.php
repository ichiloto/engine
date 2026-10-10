<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use InvalidArgumentException;

final readonly class SkitStageStyle
{
    public function __construct(public float $inactiveScale = 0.92, public float $inactiveBrightness = 0.60)
    {
        if (!is_finite($inactiveScale) || $inactiveScale < 0.5 || $inactiveScale > 1
            || !is_finite($inactiveBrightness) || $inactiveBrightness < 0 || $inactiveBrightness > 1) {
            throw new InvalidArgumentException('Skit inactive scale must be in 0.5..1 and brightness in 0..1, both finite.');
        }
    }

    public static function getFromArray(array $data): self
    {
        if (array_diff(array_keys($data), ['inactiveScale', 'inactiveBrightness']) !== []) {
            throw new InvalidArgumentException('Unsupported skit stage style option.');
        }
        foreach ($data as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Skit stage style values must be numbers.');
            }
        }
        return new self(...$data);
    }
}
