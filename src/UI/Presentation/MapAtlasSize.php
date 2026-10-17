<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

enum MapAtlasSize: string
{
    case COMPACT = 'compact';
    case LARGE = 'large';
    case OFF = 'off';
    case OPENED = 'opened';

    public static function getCornerSize(mixed $value): self
    {
        return is_string($value) ? match(self::tryFrom($value)) {
            self::LARGE=>self::LARGE, self::OFF=>self::OFF, default=>self::COMPACT,
        } : self::COMPACT;
    }

    public function getNextCornerSize(): self
    {
        return match($this) {
            self::COMPACT=>self::LARGE, self::LARGE=>self::OFF, self::OFF=>self::COMPACT,
            self::OPENED=>throw new \LogicException('Opened map zoom is independent of the corner map cycle.'),
        };
    }
}
