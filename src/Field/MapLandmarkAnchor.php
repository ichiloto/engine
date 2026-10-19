<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;

/** A resolved source-owned anchor shared by runtime and unsaved Editor models. */
final readonly class MapLandmarkAnchor
{
    public function __construct(public CellArea $area, public bool $isTransfer = false,
        public array $accessConditions = []) {}
}
