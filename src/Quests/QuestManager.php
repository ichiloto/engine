<?php

namespace Ichiloto\Engine\Quests;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Assegai\Util\Path;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Throwable;

/**
 * Drives quest progress for the running game.
 *
 * Owns the authored quest definitions (from `assets/Data/quests.php`) and
 * the party's {@see QuestLog}. Game systems report moments — an NPC talked
 * to, an enemy defeated, a map entered, a flag set, the inventory changed —
 * and the manager advances matching objectives, announces progress through
 * the notification system, and grants rewards when a quest completes.
 *
 * @package Ichiloto\Engine\Quests
 */
class QuestManager
{
  /**
   * @var QuestManager|null The manager bound to the running game scene.
   */
  protected static ?QuestManager $current = null;
  /**
   * @var array<string, Quest> The authored quests, keyed by id.
   */
  protected(set) array $quests = [];
  /**
   * @var QuestLog The party's quest progress.
   */
  protected(set) QuestLog $log;

  /**
   * QuestManager constructor.
   *
   * @param Game $game The game.
   * @param GameScene $gameScene The owning game scene.
   */
  public function __construct(
    protected Game $game,
    protected GameScene $gameScene,
  )
  {
    $this->log = new QuestLog();
    $this->quests = self::loadQuestDefinitions();
    self::$current = $this;
  }

  /**
   * Returns the manager bound to the running game scene, if any.
   *
   * @return QuestManager|null The current manager.
   */
  public static function current(): ?QuestManager
  {
    return self::$current;
  }

  /**
   * Determines whether the current project authors any quests.
   *
   * @return bool True when `assets/Data/quests.php` defines quests.
   */
  public static function projectHasQuests(): bool
  {
    return ! empty(self::loadQuestDefinitions());
  }

  /**
   * Loads the project's quest definitions.
   *
   * @return array<string, Quest> The quests, keyed by id.
   */
  public static function loadQuestDefinitions(): array
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'quests.php');

    if (! file_exists($filename)) {
      return [];
    }

    $entries = require $filename;

    if (! is_array($entries)) {
      return [];
    }

    $quests = [];

    foreach ($entries as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      try {
        $quest = Quest::fromArray($entry);
        $quests[$quest->id] = $quest;
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Skipping invalid quest definition: %s', $exception->getMessage()));
      }
    }

    return $quests;
  }

  /**
   * Restores the quest log from persisted state.
   *
   * @param array<string, mixed> $data The persisted log data.
   * @return void
   */
  public function hydrate(array $data): void
  {
    $this->log = QuestLog::fromArray($data);
    $this->refreshStatefulObjectives(quiet: true);
  }

  /**
   * Accepts a quest when its prerequisites hold.
   *
   * @param string $questId The quest id.
   * @return bool True when newly accepted.
   */
  public function acceptQuest(string $questId): bool
  {
    $quest = $this->quests[trim($questId)] ?? null;

    if ($quest === null) {
      Debug::warn(sprintf('Cannot accept unknown quest: %s', $questId));
      return false;
    }

    if (! $this->meetsPrerequisites($quest)) {
      return false;
    }

    if (! $this->log->accept($quest->id, count($quest->objectives))) {
      return false;
    }

    $this->notifyQuest('Quest Accepted', $quest->name, NotificationDuration::LONG);
    $this->refreshStatefulObjectives();

    return true;
  }

  /**
   * Records that the player talked to an NPC.
   *
   * @param string $npcName The NPC's name.
   * @return void
   */
  public function recordTalkTo(string $npcName): void
  {
    $this->advanceMatching(QuestObjectiveType::TALK_TO, $npcName);
  }

  /**
   * Records defeated enemies.
   *
   * @param string $enemyName The enemy's name.
   * @param int $count The number defeated.
   * @return void
   */
  public function recordDefeat(string $enemyName, int $count = 1): void
  {
    $this->advanceMatching(QuestObjectiveType::DEFEAT, $enemyName, max(1, $count));
  }

  /**
   * Records that a map was entered.
   *
   * @param string $mapId The map id (asset path).
   * @return void
   */
  public function recordMapEntered(string $mapId): void
  {
    $this->advanceMatching(QuestObjectiveType::REACH_MAP, $mapId, completesInstantly: true);
  }

  /**
   * Records that a switch or story event was set.
   *
   * @param string $flagName The flag name.
   * @return void
   */
  public function recordFlag(string $flagName): void
  {
    $this->advanceMatching(QuestObjectiveType::FLAG, $flagName, completesInstantly: true);
  }

  /**
   * Re-reads every inventory-backed objective from the party's items.
   *
   * @return void
   */
  public function syncCollectObjectives(): void
  {
    $party = $this->gameScene->party;

    if (! $party instanceof Party) {
      return;
    }

    foreach ($this->activeQuests() as $quest) {
      foreach ($quest->objectives as $index => $objective) {
        if ($objective->type !== QuestObjectiveType::COLLECT) {
          continue;
        }

        $held = $party->inventory->getQuantityByName($objective->target);
        $this->applyProgress($quest, $index, min($held, $objective->quantity));
      }
    }
  }

  /**
   * Determines whether a quest's status matches the expectation.
   *
   * @param string $questId The quest id.
   * @param string $status The expected status: `completed` or `active`.
   * @return bool True when the status matches.
   */
  public function questStatusMatches(string $questId, string $status = 'completed'): bool
  {
    return match ($status) {
      'active' => $this->log->isActive($questId),
      default => $this->log->isCompleted($questId),
    };
  }

  /**
   * Returns the active quests, in acceptance order.
   *
   * @return Quest[] The active quests.
   */
  public function activeQuests(): array
  {
    return $this->questsForIds($this->log->activeQuestIds());
  }

  /**
   * Returns the completed quests, in completion order.
   *
   * @return Quest[] The completed quests.
   */
  public function completedQuests(): array
  {
    return $this->questsForIds($this->log->completed);
  }

  /**
   * Returns one objective's progress count.
   *
   * @param Quest $quest The quest.
   * @param int $objectiveIndex The objective index.
   * @return int The progress count (the objective's quantity for completed quests).
   */
  public function getObjectiveProgress(Quest $quest, int $objectiveIndex): int
  {
    if ($this->log->isCompleted($quest->id)) {
      return ($quest->objectives[$objectiveIndex] ?? null)?->quantity ?? 0;
    }

    return $this->log->getProgress($quest->id)[$objectiveIndex] ?? 0;
  }

  /**
   * Re-evaluates objectives that mirror world state — held items, the
   * current map, and already-set flags — so accepting a quest the player
   * has partly satisfied starts with honest progress.
   *
   * @param bool $quiet True to suppress progress notifications (save restore).
   * @return void
   */
  public function refreshStatefulObjectives(bool $quiet = false): void
  {
    $gameState = $this->gameScene->gameState;

    foreach ($this->activeQuests() as $quest) {
      foreach ($quest->objectives as $index => $objective) {
        $count = match ($objective->type) {
          QuestObjectiveType::COLLECT => min(
            $this->gameScene->party?->inventory?->getQuantityByName($objective->target) ?? 0,
            $objective->quantity
          ),
          QuestObjectiveType::REACH_MAP => $objective->matches($this->gameScene->currentMapId)
            ? $objective->quantity
            : null,
          QuestObjectiveType::FLAG => ($gameState->getSwitch($objective->target) || $gameState->hasStoryEvent($objective->target))
            ? $objective->quantity
            : null,
          default => null,
        };

        if ($count !== null) {
          $this->applyProgress($quest, $index, $count, $quiet);
        }
      }
    }
  }

  /**
   * Advances every active objective matching the reported moment.
   *
   * @param QuestObjectiveType $type The objective type.
   * @param string $target The reported target.
   * @param int $amount The amount to advance by.
   * @param bool $completesInstantly True when the moment satisfies the objective outright.
   * @return void
   */
  protected function advanceMatching(
    QuestObjectiveType $type,
    string $target,
    int $amount = 1,
    bool $completesInstantly = false
  ): void
  {
    foreach ($this->activeQuests() as $quest) {
      foreach ($quest->objectives as $index => $objective) {
        if ($objective->type !== $type || ! $objective->matches($target)) {
          continue;
        }

        $count = $completesInstantly
          ? $objective->quantity
          : min($objective->quantity, $this->getObjectiveProgress($quest, $index) + $amount);

        $this->applyProgress($quest, $index, $count);
      }
    }
  }

  /**
   * Writes one objective's progress, announcing changes and handling
   * quest completion.
   *
   * @param Quest $quest The quest.
   * @param int $objectiveIndex The objective index.
   * @param int $count The new progress count.
   * @param bool $quiet True to suppress notifications.
   * @return void
   */
  protected function applyProgress(Quest $quest, int $objectiveIndex, int $count, bool $quiet = false): void
  {
    if (! $this->log->setProgress($quest->id, $objectiveIndex, $count)) {
      return;
    }

    if ($this->isQuestSatisfied($quest)) {
      $this->completeQuest($quest, $quiet);
      return;
    }

    if (! $quiet) {
      $objective = $quest->objectives[$objectiveIndex];
      $progress = $objective->quantity > 1
        ? sprintf('%s (%d/%d)', $objective->description, $count, $objective->quantity)
        : $objective->description;
      $this->notifyQuest('Quest Updated', sprintf("%s\n%s", $quest->name, $progress), NotificationDuration::MEDIUM);
    }
  }

  /**
   * Determines whether every objective of an active quest is satisfied.
   *
   * @param Quest $quest The quest.
   * @return bool True when the quest can complete.
   */
  protected function isQuestSatisfied(Quest $quest): bool
  {
    $progress = $this->log->getProgress($quest->id);

    foreach ($quest->objectives as $index => $objective) {
      if (($progress[$index] ?? 0) < $objective->quantity) {
        return false;
      }
    }

    return ! empty($quest->objectives);
  }

  /**
   * Completes a quest: moves it in the log, grants rewards, records the
   * `quest_completed:<id>` story event, and announces it.
   *
   * @param Quest $quest The quest.
   * @param bool $quiet True to suppress notifications.
   * @return void
   */
  protected function completeQuest(Quest $quest, bool $quiet = false): void
  {
    if (! $this->log->markCompleted($quest->id)) {
      return;
    }

    $this->grantRewards($quest);
    $this->gameScene->gameState->recordStoryEvent(sprintf('quest_completed:%s', $quest->id));

    if (! $quiet) {
      $text = $quest->name;

      if (($rewards = $quest->describeRewards()) !== '') {
        $text .= sprintf("\nReward: %s", $rewards);
      }

      $this->notifyQuest('Quest Complete', $text, NotificationDuration::LONG);
    }
  }

  /**
   * Grants a quest's rewards to the party.
   *
   * @param Quest $quest The quest.
   * @return void
   */
  protected function grantRewards(Quest $quest): void
  {
    $party = $this->gameScene->party;

    if (! $party instanceof Party) {
      return;
    }

    if (($gold = intval($quest->rewards['gold'] ?? 0)) > 0) {
      $party->credit($gold);
    }

    if (($experience = intval($quest->rewards['experience'] ?? 0)) > 0) {
      foreach ($party->members->toArray() as $member) {
        $member->addExperience($experience);
      }
    }

    $itemNames = array_values(array_filter((array) ($quest->rewards['items'] ?? []), is_string(...)));

    if (! empty($itemNames)) {
      $itemStore = ConfigStore::get(ItemStore::class);

      if ($itemStore instanceof ItemStore) {
        $party->addItems(...$itemStore->load($itemNames));
      }
    }
  }

  /**
   * Determines whether a quest's prerequisites hold.
   *
   * @param Quest $quest The quest.
   * @return bool True when every prerequisite passes.
   */
  protected function meetsPrerequisites(Quest $quest): bool
  {
    return WorldConditionEvaluator::allHold(
      $quest->prerequisites,
      $this->gameScene->gameState,
      $this->gameScene->party
    );
  }

  /**
   * Resolves quest ids to their definitions, skipping unknown ids.
   *
   * @param string[] $questIds The quest ids.
   * @return Quest[] The known quests.
   */
  protected function questsForIds(array $questIds): array
  {
    return array_values(array_filter(array_map(
      fn(string $questId): ?Quest => $this->quests[$questId] ?? null,
      $questIds
    )));
  }

  /**
   * Shows a quest notification.
   *
   * @param string $title The notification title.
   * @param string $text The notification text.
   * @param NotificationDuration $duration The display duration.
   * @return void
   */
  protected function notifyQuest(string $title, string $text, NotificationDuration $duration): void
  {
    try {
      notify($this->game, NotificationChannel::QUEST, $title, $text, $duration);
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Quest notification failed: %s', $exception->getMessage()));
    }
  }
}
