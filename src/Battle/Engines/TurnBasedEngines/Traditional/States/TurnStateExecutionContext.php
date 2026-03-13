<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

/**
 * Class TurnStateExecutionContext. Represents the context of a turn state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class TurnStateExecutionContext
{
  /**
   * @var Turn[] The turns to resolve this round.
   */
  protected array $turns = [];
  /**
   * @var int The current turn cursor.
   */
  protected int $turnCursor = 0;

  /**
   * TurnStateExecutionContext constructor.
   *
   * @param Game $game The game.
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param BattleScreen $ui The battle screen.
   * @param array $args The arguments.
   */
  public function __construct(
    protected(set) Game $game,
    protected(set) Party $party,
    protected(set) Troop $troop,
    protected(set) BattleScreen $ui,
    protected(set) array $args
  )
  {
  }

  /**
   * Sets the turns for the current round.
   *
   * @param Turn[] $turns The turns to resolve.
   * @return void
   */
  public function setTurns(array $turns): void
  {
    $this->turns = array_values($turns);
    $this->turnCursor = 0;
  }

  /**
   * Returns the current round turns.
   *
   * @return Turn[]
   */
  public function getTurns(): array
  {
    return $this->turns;
  }

  /**
   * Resets the turn cursor.
   *
   * @return void
   */
  public function resetTurnCursor(): void
  {
    $this->turnCursor = 0;
  }

  /**
   * Returns the current turn.
   *
   * @return Turn|null
   */
  public function getCurrentTurn(): ?Turn
  {
    return $this->turns[$this->turnCursor] ?? null;
  }

  /**
   * Advances to the next turn.
   *
   * @return void
   */
  public function advanceTurn(): void
  {
    $this->turnCursor++;
  }

  /**
   * Returns the turn for the given battler.
   *
   * @param CharacterInterface $battler The battler to find.
   * @return Turn|null
   */
  public function findTurnForBattler(CharacterInterface $battler): ?Turn
  {
    foreach ($this->turns as $turn) {
      if ($turn->battler === $battler) {
        return $turn;
      }
    }

    return null;
  }

  /**
   * Returns the living party battlers.
   *
   * @return CharacterInterface[]
   */
  public function getLivingPartyBattlers(): array
  {
    return array_values(array_filter(
      $this->party->battlers->toArray(),
      fn(CharacterInterface $battler) => ! $battler->isKnockedOut
    ));
  }

  /**
   * Returns the living troop battlers.
   *
   * @return CharacterInterface[]
   */
  public function getLivingTroopBattlers(): array
  {
    return array_values(array_filter(
      $this->troop->members->toArray(),
      fn(CharacterInterface $battler) => ! $battler->isKnockedOut
    ));
  }

  /**
   * Returns the living opponents for the given battler.
   *
   * @param CharacterInterface $battler The battler whose opponents are required.
   * @return CharacterInterface[]
   */
  public function getLivingOpponents(CharacterInterface $battler): array
  {
    $partyBattlers = $this->party->battlers->toArray();
    $isPartyBattler = in_array($battler, $partyBattlers, true);

    return $isPartyBattler
      ? $this->getLivingTroopBattlers()
      : $this->getLivingPartyBattlers();
  }
}
