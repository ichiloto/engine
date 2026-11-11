<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Campaign;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;

/** Campaign facts consumed by progression, independently of field presentation. */
interface CampaignContextInterface
{
    public GameState $gameState { get; }
    public ?Party $party { get; }
    public string $currentMapId { get; }
}
