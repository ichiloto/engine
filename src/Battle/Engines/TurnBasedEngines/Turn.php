<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Represents a turn in a turn-based battle engine.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines
 */
class Turn
{
  /**
   * @var bool Whether the turn is completed.
   */
  protected(set) bool $isCompleted = false;
  /**
   * @var BattleAction|null The action to be executed.
   */
  public ?BattleAction $action = null;
  /**
   * @var CharacterInterface[] The targets of the action.
   */
  public array $targets = [];

  /**
   * Turn constructor.
   *
   * @param CharacterInterface $battler The battler.
   */
  public function __construct(
    protected(set) CharacterInterface $battler,
  )
  {
  }

  /**
   * Starts the turn.
   *
   * @return void
   */
  public function start(): void
  {
    $this->isCompleted = false;
  }

  /**
   * Executes the turn.
   *
   * @param TurnExecutionContext $context The turn execution context.
   */
  public function execute(TurnExecutionContext $context): void
  {
    if (!$this->action) {
      $context->battleConfig->ui->alert('No action set for turn.');
    }

    $this->action->execute($this->battler, $this->targets);

    $this->complete();
  }

  /**
   * Completes the turn.
   *
   * @return void
   */
  public function complete(): void
  {
    $this->isCompleted = true;
  }
}