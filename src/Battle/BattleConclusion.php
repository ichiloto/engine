<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Events\BattleOutcome;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Progression\Mastery\MasteryVocabulary;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Ends a battle with an outcome: builds its result, awards victory rewards
 * and hands the scene to the matching results state. Every way a battle can
 * end (the last enemy falling, the party falling, an escape or a battle
 * event) goes through here, so field scripts always see the same result.
 *
 * @package Ichiloto\Engine\Battle
 */
final readonly class BattleConclusion
{
  public function __construct(
    private Game $game,
    private Party $party,
    private Troop $troop,
  )
  {
  }

  /** Ends the battle with the outcome. */
  public function conclude(BattleScene $scene, BattleOutcome $outcome): void
  {
    match ($outcome) {
      BattleOutcome::VICTORY => $this->concludeVictory($scene),
      BattleOutcome::ESCAPE => $this->concludeEscape($scene),
      BattleOutcome::DEFEAT => $this->concludeDefeat($scene),
    };
  }

  /**
   * Awards the troop's rewards and shows the victory results. Every troop
   * member counts as defeated, whether knocked out or conceded.
   */
  public function concludeVictory(BattleScene $scene): void
  {
    $experience = 0;
    $skillPoints = 0;
    $gold = 0;
    $items = [];

    foreach ($this->troop->members->toArray() as $enemy) {
      $experience += $enemy->rewards->experience;
      $skillPoints += $enemy->rewards->skillPoints;
      $gold += $enemy->rewards->gold;

      if ($item = $enemy->rewards->item) {
        $items[] = clone $item;
      }
    }

    $progressionResults = ExperienceAwarder::awardParty($this->party, $experience, $skillPoints);
    $levelUps = array_values(array_filter(
      $progressionResults,
      static fn($result): bool => $result->levelledUp(),
    ));

    // Inventory insertion can coalesce quantities; snapshot the actual drop batch first.
    $rewardItems = BattleRewards::snapshotItems($items);
    $heldBefore = array_column($this->party->inventory->all->toArray(), 'quantity', 'id');
    $goldBefore = $this->party->accountBalance;
    $this->party->credit($gold);

    if (! empty($items)) {
      $this->party->addItems(...$items);
    }
    $heldAfter = array_column($this->party->inventory->all->toArray(), 'quantity', 'id');
    foreach ($rewardItems as &$rewardItem) {
      $rewardItem['received'] = max(0, ($heldAfter[$rewardItem['id']] ?? 0) - ($heldBefore[$rewardItem['id']] ?? 0));
    }
    unset($rewardItem);
    $rewardSummary = ['Enemies defeated' => (string)count($this->troop->members->toArray())];
    $goldReceived = $this->party->accountBalance - $goldBefore;
    if ($goldReceived !== $gold) {
      $rewardSummary[get_engine_message('battle.currency_at_capacity',
        Vocabulary::getTerm('currency.name', 'Gold'))] = (string)($gold - $goldReceived);
    }

    $questManager = QuestManager::current();
    $gameScene = $this->game->sceneManager->findScene(GameScene::class);
    $knowledge = $gameScene instanceof GameScene && $gameScene->isStarted() ? $gameScene->knowledge : null;

    foreach ($this->troop->members->toArray() as $enemy) {
      $questManager?->recordDefeat($enemy->name);
      $knowledge?->recordEnemyOutcome($enemy, 'defeated');
    }

    $lines = [
      get_engine_message('obtained_exp', $experience, Vocabulary::getTerm('stats.exp', 'EXP')),
      get_engine_message('obtained_gold', $gold, Vocabulary::getTerm('currency.symbol', 'G')),
    ];
    if ($skillPoints > 0) {
      $lines[] = get_engine_message('obtained_skill_points', $skillPoints, MasteryVocabulary::getPointsLabel());
    }
    $entries = [
      ['label' => Vocabulary::getTerm('stats.exp', 'EXP') . ':', 'value' => (string)$experience],
      ...($skillPoints > 0 ? [['label' => MasteryVocabulary::getPointsLabel() . ':', 'value' => (string)$skillPoints]] : []),
      ['label' => Vocabulary::getTerm('currency.name', 'Gold') . ':',
        'value' => trim(sprintf('%d %s', $gold, Vocabulary::getTerm('currency.symbol', 'G')))],
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
      $lines[] = get_engine_message('battle.level_gained', $levelUp->character->name,
        Vocabulary::getTerm('stats.level'), $levelUp->newLevel);
      $entries[] = [
        'label' => sprintf('%s:', $levelUp->character->name),
        'value' => sprintf('%s %d!', Vocabulary::getTerm('stats.level'), $levelUp->newLevel),
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
      new BattleRewards($experience, $goldReceived, $progressionResults, $rewardItems, $rewardSummary,
        skillPointsPerMember: $skillPoints));
    $scene->setState($scene->victoryState);
  }

  /** Shows the defeat results; the scene's defeat policy decides what follows. */
  public function concludeDefeat(BattleScene $scene): void
  {
    $scene->result = new BattleResult('Defeat', [
      'The party has been wiped out.',
      'Press enter to continue.',
    ]);
    $scene->setState($scene->defeatState);
  }

  /** Ends the battle as an escape, with no rewards. */
  public function concludeEscape(BattleScene $scene): void
  {
    play_sound(SystemSound::ESCAPE);
    $scene->result = new BattleResult('Escaped', [
      'The party slipped away!',
      'Press enter to continue.',
    ]);
    $scene->shouldLoadGameOver = false;
    $scene->setState($scene->victoryState);
  }
}
