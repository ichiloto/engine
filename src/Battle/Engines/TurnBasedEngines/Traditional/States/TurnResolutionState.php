<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\IO\Enumerations\Color;
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

    $this->applyStateTicks($context);

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

      $context->party->credit($gold);

      if (! empty($items)) {
        $context->party->addItems(...$items);
      }

      $questManager = QuestManager::current();
      $gameScene = $context->game->sceneManager->findScene(GameScene::class);
      $bestiary = $gameScene instanceof GameScene && $gameScene->isStarted() ? $gameScene->bestiary : null;

      foreach ($context->troop->members->toArray() as $enemy) {
        $questManager?->recordDefeat($enemy->name);
        $bestiary?->recordDefeated($enemy->name);
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

      $scene->result = new BattleResult('Victory', $lines, $items, $entries);
      $scene->setState($scene->victoryState);
      return;
    }

    $this->setState($this->engine->turnInitState);
  }

  /**
   * Applies one round of state ticks to every living battler: HP deltas
   * (with popups) and duration expiry (with a summary alert).
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function applyStateTicks(TurnStateExecutionContext $context): void
  {
    $announcements = [];

    foreach ([...$context->getLivingPartyBattlers(), ...$context->getLivingTroopBattlers()] as $battler) {
      if (! method_exists($battler, 'tickStates')) {
        continue;
      }

      $events = $battler->tickStates();
      $popupLines = $this->buildStateTickPopupLines($events);

      foreach ($events as $event) {
        if ($event['expired']) {
          $announcements[] = sprintf('%s recovered from %s.', $battler->name, $event['state']->name);
        }
      }

      if (! empty($popupLines)) {
        $context->ui->fieldWindow->showStatChangePopup($battler, $popupLines);
      }
    }

    if (! empty($announcements)) {
      $context->ui->alert(implode(' ', $announcements));
    }
  }

  /**
   * Converts state ticks into the same typed popup payload used by actions.
   *
   * @param array<int, array{state: object, hpDelta: int, expired: bool}> $events State tick events.
   * @return array<int, array{text: string, color: Color}> Popup lines.
   */
  protected function buildStateTickPopupLines(array $events): array
  {
    $lines = [];

    foreach ($events as $event) {
      if ($event['hpDelta'] === 0) {
        continue;
      }

      $lines[] = [
        'text' => sprintf('%+d %s', $event['hpDelta'], $event['state']->name),
        'color' => $event['hpDelta'] < 0 ? Color::LIGHT_RED : Color::LIGHT_GREEN,
      ];
    }

    return $lines;
  }
}
