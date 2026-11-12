<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;

/** A resolved source-owned anchor shared by runtime and unsaved Editor models. */
final readonly class MapLandmarkAnchor
{
    public bool $isRoute;
    public function __construct(public CellArea $area, public bool $isTransfer = false,
        public array $accessConditions = [], public bool $isPassage = false)
    {
        $this->isRoute = $isTransfer || $isPassage;
    }
}
