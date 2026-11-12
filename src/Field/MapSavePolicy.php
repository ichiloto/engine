<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use InvalidArgumentException;

/** Authored permission to save on a map, independent of its display name. */
enum MapSavePolicy: string
{
    case SAVE_POINTS = 'save-points';
    case ANYWHERE = 'anywhere';

    public static function readMap(array $data): self
    {
        if (!array_key_exists('savePolicy', $data)) { return self::SAVE_POINTS; }
        $value = $data['savePolicy'];
        return (is_string($value) ? self::tryFrom($value) : null)
            ?? throw new InvalidArgumentException('Map savePolicy must be anywhere or save-points.');
    }
}
