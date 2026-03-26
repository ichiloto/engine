<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Scenes\Battle\BattleScene;

/**
 * Represents the turn resolution state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class TurnResolutionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $scene = $context->game->sceneManager->currentScene;

    if (! $scene instanceof BattleScene) {
      return;
    }

    if (empty($context->getLivingPartyBattlers())) {
      $scene->result = new BattleResult('Defeat', [
        'The party has been wiped out.',
        'Press enter to continue.',
      ]);
      $scene->setState($scene->defeatState);
      return;
    }

    if (empty($context->getLivingTroopBattlers())) {
      $experience = 0;
      $gold = 0;
      $items = [];

      foreach ($context->troop->members->toArray() as $enemy) {
        $experience += $enemy->rewards->experience;
        $gold += $enemy->rewards->gold;

        if ($item = $enemy->rewards->item) {
          $items[] = clone $item;
        }
      }

      foreach ($context->party->members->toArray() as $member) {
        $member->addExperience($experience);
      }

      $context->party->credit($gold);

      if (! empty($items)) {
        $context->party->addItems(...$items);
      }

      $lines = [
        sprintf('Experience gained: %d', $experience),
        sprintf('Gold found: %dG', $gold),
      ];
      $entries = [
        ['label' => 'Experience gained:', 'value' => (string)$experience],
        ['label' => 'Gold found:', 'value' => sprintf('%dG', $gold)],
      ];

      if (! empty($items)) {
        $lines[] = 'Loot: ' . implode(', ', array_map(fn($item) => $item->name, $items));
        $entries[] = [
          'label' => 'Item drops:',
          'value' => implode(', ', array_map(fn($item) => $item->name, $items)),
        ];
      }

      $scene->result = new BattleResult('Victory', $lines, $items, $entries);
      $scene->setState($scene->victoryState);
      return;
    }

    $this->setState($this->engine->turnInitState);
  }
}
