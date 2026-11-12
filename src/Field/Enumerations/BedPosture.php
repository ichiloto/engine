<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Enumerations;

enum BedPosture: string
{
    case LYING = 'lying';
    case SITTING = 'sitting';
    case STANDING = 'standing';
}
