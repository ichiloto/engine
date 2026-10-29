<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Interfaces\BattleActionInterface;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\ItemScope;

/**
 * Class BattleAction. Represents an action that can be executed in a battle.
 *
 * @package Ichiloto\Engine\Battle
 */
abstract class BattleAction implements BattleActionInterface
{
  protected CombatResolver $resolver;
  protected CombatRandomSource $random;
  protected(set) ?CombatActionResult $lastResult = null;
  private int $executionSequence = 0;
  protected ?BattleReactions $reactions = null;
  protected bool $allowInterception = true;
  protected ?BattleGaugeControl $gauges = null;
  protected ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formation = null;

  /** A battle injects its own reaction owner only for the duration of execution. */
  final public function executeWithReactions(Actor $actor, array $targets, BattleReactions $reactions,
    bool $allowInterception = true, ?BattleGaugeControl $gauges = null,
    ?\Ichiloto\Engine\Battle\Formation\BattleFormation $formation = null): void
  {
    $this->lastResult = null;
    $this->reactions = $reactions;
    $this->allowInterception = $allowInterception;
    $this->gauges = $gauges;
    $this->formation = $formation;
    try { $this->execute($actor, $targets); }
    finally { $this->reactions = null; $this->allowInterception = true; $this->gauges = null; $this->formation = null; }
  }
  /** The command's targeting contract, shared by selection and execution. */
  public ItemScope $targetScope {
    get { return new ItemScope(); }
  }
  public ?\Ichiloto\Engine\Battle\Formation\BattleTargeting $targeting {
    get => null;
  }
  /**
   * BattleAction constructor.
   *
   * @param string $name The name of the action.
   */
  public function __construct(
    protected(set) string $name,
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
  )
  {
    $this->resolver = $resolver ?? new CombatResolver();
    $this->random = $random ?? new NativeCombatRandomSource();
    $this->configure();
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    // Do nothing
  }

  protected function nextExecutionId(): string
  {
    $this->executionSequence++;
    return sprintf('%s:%d', strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->name) ?? 'action'), $this->executionSequence);
  }
}
