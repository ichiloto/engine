<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldStateWriter;
use RuntimeException;
use Throwable;

/** Applies one matching battle-entry rule as an atomic transaction. */
final class BattleEntryRuleExecutor
{
  public function apply(BattleEntryRule $rule, BattleEntryContext $context, GameState $worldState): void
  {
    /** @var array<string, array{actor: BattleEntryActor, stat: string, stage: int}> $originalStages */
    $originalStages = [];

    foreach ($rule->effects as $index => $effect) {
      $actor = $context->actor($effect->actorId);
      if (! $actor instanceof BattleEntryActor) {
        throw new RuntimeException(sprintf(
          '%s field "effects[%d].actor" references actor "%s", who did not begin this battle.',
          $rule->source,
          $index,
          $effect->actorId,
        ));
      }

      $key = spl_object_id($actor->character) . ':' . $effect->stat->value;
      $originalStages[$key] ??= [
        'actor' => $actor,
        'stat' => $effect->stat->value,
        'stage' => $actor->character->getStatStage($effect->stat->value),
      ];
    }

    // Validation runs again at the mutation boundary so manually-constructed
    // rules receive the same fail-closed contract as project-loaded rules.
    WorldStateWriter::validateAll($rule->writes, $rule->source . ' field "writes"', transactional: true);

    $worldSnapshot = $worldState->toArray();
    $observer = $worldState->onChange;
    /** @var array<int, array{0: string, 1: string}> $notifications */
    $notifications = [];
    $worldState->onChange = static function (string $kind, string $name) use (&$notifications): void {
      $notifications[] = [$kind, $name];
    };

    try {
      foreach ($rule->effects as $effect) {
        $actor = $context->actor($effect->actorId);
        if (! $actor instanceof BattleEntryActor) {
          throw new RuntimeException(sprintf(
            '%s could not resolve actor "%s" while applying a stat-stage effect.',
            $rule->source,
            $effect->actorId,
          ));
        }

        $actor->character->addStatStage($effect->stat->value, $effect->delta);
      }

      WorldStateWriter::applyAllStrict($rule->writes, $worldState, $rule->source . ' field "writes"');
      $worldState->onChange = $observer;

      foreach ($notifications as [$kind, $name]) {
        $observer?->__invoke($kind, $name);
      }
    } catch (Throwable $exception) {
      $worldState->onChange = $observer;
      $worldState->restoreSnapshot($worldSnapshot);

      foreach ($originalStages as $original) {
        $original['actor']->character->setStatStage($original['stat'], $original['stage']);
      }

      throw new RuntimeException(sprintf(
        '%s failed atomically; temporary effects and durable writes were rolled back: %s',
        $rule->source,
        $exception->getMessage(),
      ), previous: $exception);
    }
  }
}
