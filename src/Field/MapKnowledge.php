<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;

/** Gameplay discovery. Presentation reads this state without causing discoveries. */
final readonly class MapKnowledge
{
    public function __construct(public string $mapId, public MapLandmarkCatalog $catalog) {}

    /** Approach and authored play revelations latch into the existing saved world state. */
    public function discoverNear(int $x, int $y, GameState $state, ?Party $party = null): void
    {
        foreach ($this->catalog->landmarks as $landmark) {
            if ($landmark->automatic || $state->hasRevealedLandmark($this->mapId, $landmark->id)) { continue; }
            if ($landmark->isNear($x, $y) || ($landmark->revealConditions !== []
                && WorldConditionEvaluator::allHold($landmark->revealConditions, $state, $party))) {
                $state->revealLandmark($this->mapId, $landmark->id);
            }
        }
    }

    /** @return list<array{definition: MapLandmarkDefinition, name: ?string, accessible: ?bool}> */
    public function getVisibleLandmarks(GameState $state, ?Party $party = null): array
    {
        $visible = [];
        foreach ($this->catalog->landmarks as $landmark) {
            $known = $state->hasRevealedLandmark($this->mapId, $landmark->id);
            if (!$known && ($landmark->secret || !$landmark->kind->isRoute())) { continue; }
            $visible[] = ['definition' => $landmark, 'name' => $known ? $landmark->name?->getText() : null,
                'accessible' => $landmark->accessConditions === null ? null
                    : WorldConditionEvaluator::allHold($landmark->accessConditions, $state, $party)];
        }
        return $visible;
    }
}
