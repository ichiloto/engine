<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;

/**
 * What troop event conditions are tested against at one battle check.
 *
 * @package Ichiloto\Engine\Battle\Events
 */
final readonly class BattleEventCheck
{
  /**
   * @param int $turn The battle turn, 1 for the opening turn.
   * @param array<int, Enemy> $members The troop's enemies, keyed by their authored member index.
   * @param list<Character> $partyBattlers The party members fighting in this battle.
   * @param GameState $worldState The world state switches, variables and story events are read from.
   * @param Party|null $party The party, for item conditions.
   */
  public function __construct(
    public int $turn,
    public array $members,
    public array $partyBattlers,
    public GameState $worldState,
    public ?Party $party = null,
  )
  {
  }

  /** Returns the party battler with the actor ID, if it fights in this battle. */
  public function findPartyBattler(string $actorId): ?Character
  {
    foreach ($this->partyBattlers as $battler) {
      if ($battler->actorId === $actorId) {
        return $battler;
      }
    }

    return null;
  }
}
