<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Interfaces\BattleActionInterface;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;

/**
 * Class BattleAction. Represents an action that can be executed in a battle.
 *
 * @package Ichiloto\Engine\Battle
 */
abstract class BattleAction implements BattleActionInterface
{
  /**
   * BattleAction constructor.
   *
   * @param string $name The name of the action.
   */
  public function __construct(
    protected(set) string $name
  )
  {
    $this->configure();
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    // Do nothing
  }
}