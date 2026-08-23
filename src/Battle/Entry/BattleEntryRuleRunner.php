<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/** Evaluates the ordered project rules once for one battle execution. */
final class BattleEntryRuleRunner
{
  public function __construct(
    private BattleEntryRuleCatalog $catalog,
    private BattleEntryRuleExecutor $executor = new BattleEntryRuleExecutor(),
  )
  {
  }

  public function apply(BattleConfig $config, GameState $worldState): void
  {
    if ($config->entryRulesEvaluated()) {
      return;
    }

    $context = $config->entryContext($worldState);

    foreach ($this->catalog->rules() as $rule) {
      if ($config->hasAppliedEntryRule($rule->id)) {
        continue;
      }

      if ($rule->classification !== $context->classification) {
        continue;
      }

      $actorsPresent = array_all(
        $rule->actors,
        static fn(BattleEntryActorPredicate $predicate): bool =>
          $context->hasActor($predicate->actorId, $predicate->presence),
      );

      if (! $actorsPresent || ! $context->conditionsHold($rule->conditions)) {
        continue;
      }

      $this->executor->apply($rule, $context, $worldState);
      $config->markEntryRuleApplied($rule->id);
    }

    $config->markEntryRulesEvaluated();
  }
}
