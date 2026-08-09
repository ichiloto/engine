<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;

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

      $levelUps = [];

      foreach ($context->party->members->toArray() as $member) {
        $previousLevel = $member->level;
        $member->addExperience($experience);

        if ($member->level <= $previousLevel) {
          continue;
        }

        // Consume the role's level-gated skill grants for every level
        // crossed this battle.
        $learnedSkillNames = [];

        foreach ($member->role->skillsToLearn as $skillToLearn) {
          if (
            $skillToLearn->level > $previousLevel
            && $skillToLearn->level <= $member->level
            && $member->abilityBook->learnSkillDirectly($skillToLearn->skill)
          ) {
            $learnedSkillNames[] = $skillToLearn->skill->name;
          }
        }

        $levelUps[] = [
          'name' => $member->name,
          'level' => $member->level,
          'skills' => $learnedSkillNames,
        ];
      }

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
        $lines[] = sprintf('%s grew to level %d!', $levelUp['name'], $levelUp['level']);
        $entries[] = [
          'label' => sprintf('%s:', $levelUp['name']),
          'value' => sprintf('Level %d!', $levelUp['level']),
        ];

        foreach ($levelUp['skills'] as $skillName) {
          $lines[] = sprintf('%s learned %s!', $levelUp['name'], $skillName);
          $entries[] = [
            'label' => sprintf('%s learned:', $levelUp['name']),
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

      $popupLines = [];

      foreach ($battler->tickStates() as $event) {
        if ($event['hpDelta'] !== 0) {
          $popupLines[] = sprintf('%+d %s', $event['hpDelta'], $event['state']->name);
        }

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
}
