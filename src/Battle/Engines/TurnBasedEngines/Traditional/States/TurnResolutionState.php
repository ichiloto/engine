<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Progression\ExperienceAwarder;

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

      $progressionResults = ExperienceAwarder::awardParty($context->party, $experience);
      $levelUps = array_values(array_filter(
        $progressionResults,
        static fn($result): bool => $result->levelledUp(),
      ));

      // Inventory insertion can coalesce quantities; snapshot the actual drop batch first.
      $rewardItems = BattleRewards::snapshotItems($items);
      $heldBefore = array_column($context->party->inventory->all->toArray(), 'quantity', 'id');
      $goldBefore = $context->party->accountBalance;
      $context->party->credit($gold);

      if (! empty($items)) {
        $context->party->addItems(...$items);
      }
      $heldAfter = array_column($context->party->inventory->all->toArray(), 'quantity', 'id');
      foreach ($rewardItems as &$rewardItem) {
        $rewardItem['received'] = max(0, ($heldAfter[$rewardItem['id']] ?? 0) - ($heldBefore[$rewardItem['id']] ?? 0));
      }
      unset($rewardItem);
      $rewardSummary = ['Enemies defeated' => (string)count($context->troop->members->toArray())];
      $goldReceived = $context->party->accountBalance - $goldBefore;
      if ($goldReceived !== $gold) { $rewardSummary['Gold at capacity'] = (string)($gold - $goldReceived); }

      $questManager = QuestManager::current();
      $gameScene = $context->game->sceneManager->findScene(GameScene::class);
      $knowledge = $gameScene instanceof GameScene && $gameScene->isStarted() ? $gameScene->knowledge : null;

      foreach ($context->troop->members->toArray() as $enemy) {
        $questManager?->recordDefeat($enemy->name);
        $knowledge?->recordEnemyOutcome($enemy, 'defeated');
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

      if (! empty($levelUps)) {
        play_sound(SystemSound::LEVEL_UP);
      }

      foreach ($levelUps as $levelUp) {
        $lines[] = sprintf('%s grew to level %d!', $levelUp->character->name, $levelUp->newLevel);
        $entries[] = [
          'label' => sprintf('%s:', $levelUp->character->name),
          'value' => sprintf('Level %d!', $levelUp->newLevel),
        ];

        foreach ($levelUp->learnedSkills() as $skillName) {
          $lines[] = sprintf('%s learned %s!', $levelUp->character->name, $skillName);
          $entries[] = [
            'label' => sprintf('%s learned:', $levelUp->character->name),
            'value' => $skillName,
          ];
        }
      }

      $scene->result = new BattleResult('Victory', $lines, $items, $entries,
        new BattleRewards($experience, $goldReceived, $progressionResults, $rewardItems, $rewardSummary));
      $scene->setState($scene->victoryState);
      return;
    }

    $this->setState($this->engine->turnInitState);
  }

}
