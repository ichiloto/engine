<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\Skill;

class EnemyActionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $partyTargets = $context->getLivingPartyBattlers();

    if (empty($partyTargets)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    $maxPartyLevel = 1;

    foreach ($partyTargets as $battler) {
      if ($battler instanceof Character) {
        $maxPartyLevel = max($maxPartyLevel, $battler->level);
      }
    }

    foreach ($context->getLivingTroopBattlers() as $enemy) {
      $turn = $context->findTurnForBattler($enemy);

      if ($turn === null) {
        continue;
      }

      [$turn->action, $turn->targets] = $this->chooseEnemyAction($context, $enemy, $partyTargets, $maxPartyLevel);
    }

    $context->ui->commandContextWindow->clear();

    $this->setState($this->engine->actionExecutionState);
  }

  /**
   * Chooses an enemy's action and targets from its authored patterns,
   * falling back to a basic attack on a random party member.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $enemy The acting enemy.
   * @param CharacterInterface[] $partyTargets The living party battlers.
   * @param int $maxPartyLevel The highest level in the player party.
   * @return array{0: \Ichiloto\Engine\Battle\BattleAction, 1: CharacterInterface[]} The action and its targets.
   */
  protected function chooseEnemyAction(
    TurnStateExecutionContext $context,
    CharacterInterface $enemy,
    array $partyTargets,
    int $maxPartyLevel
  ): array
  {
    $patterns = $enemy instanceof Enemy ? $enemy->actionPatterns : [];

    if (! empty($patterns)) {
      $usable = EnemyActionEvaluator::filterUsablePatterns(
        $patterns,
        $enemy,
        $context->roundNumber,
        $maxPartyLevel
      );
      $pattern = EnemyActionEvaluator::pickPattern($usable);

      if ($pattern !== null && $pattern->skill instanceof Skill) {
        return [
          new SkillBattleAction($pattern->skill),
          $this->resolveEnemyTargets($context, $enemy, $pattern->skill, $partyTargets),
        ];
      }
    }

    return [new AttackAction('Attack'), [$partyTargets[array_rand($partyTargets)]]];
  }

  /**
   * Resolves a chosen skill's targets from the enemy's perspective:
   * the "enemy" side is the player party, allies are the troop.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $enemy The acting enemy.
   * @param Skill $skill The chosen skill.
   * @param CharacterInterface[] $partyTargets The living party battlers.
   * @return CharacterInterface[] The resolved targets.
   */
  protected function resolveEnemyTargets(
    TurnStateExecutionContext $context,
    CharacterInterface $enemy,
    Skill $skill,
    array $partyTargets
  ): array
  {
    $pool = match ($skill->scope->side) {
      ItemScopeSide::USER => [$enemy],
      ItemScopeSide::ALLY => $context->getLivingTroopBattlers(),
      default => $partyTargets,
    };

    if (empty($pool)) {
      return [$enemy];
    }

    return $skill->scope->number === ItemScopeNumber::ALL
      ? $pool
      : [$pool[array_rand($pool)]];
  }
}
