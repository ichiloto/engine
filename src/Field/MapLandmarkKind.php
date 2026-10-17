<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/** Map semantics supplied by the author, independent of glyphs and artwork. */
enum MapLandmarkKind: string
{
    case PASSAGE = 'passage';
    case ENTRANCE = 'entrance';
    case EXIT = 'exit';
    case BUILDING = 'building';
    case LANDMARK = 'landmark';

    public function isRoute(): bool
    {
        return in_array($this, [self::PASSAGE, self::ENTRANCE, self::EXIT], true);
    }
}
