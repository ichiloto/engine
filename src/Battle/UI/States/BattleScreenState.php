<?php

namespace Ichiloto\Engine\Battle\UI\States;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;

/**
 * Represents a battle screen state.
 *
 * @package Ichiloto\Engine\Battle\UI\States
 */
abstract class BattleScreenState
{
  public function __construct(
    protected BattleScreen $battleScreen
  )
  {
  }

  /**
   * Enters the state.
   *
   * @return void
   */
  public function enter(): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * Updates the state.
   *
   * @return void
   */
  public abstract function update(): void;

  /**
   * Exits the state.
   *
   * @return void
   */
  public function exit(): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * Sets the state of the battle screen.
   *
   * @param BattleScreenState $state The state to set.
   * @return void
   */
  public function setState(BattleScreenState $state): void
  {
    $this->battleScreen->setState($state);
  }

  /**
   * Selects the previous option.
   *
   * @param int $step The number of steps to move.
   * @return void
   */
  public function selectPrevious(int $step = 1): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * Selects the next option.
   *
   * @param int $step The number of steps to move.
   * @return void
   */
  public function selectNext(int $step = 1): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * Confirms the current selection.
   *
   * @return void
   */
  public function confirm(): void
  {
    // Do nothing. This method should be overridden by the child class.
  }

  /**
   * Cancels the current selection.
   *
   * @return void
   */
  public function cancel(): void
  {
    // Do nothing. This method should be overridden by the child class.
  }
}