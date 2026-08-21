<?php

namespace Ichiloto\Engine\Progress;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Events\AchievementEvent;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Tracks the player's achievements.
 *
 * Definitions load from `assets/Data/achievements.php`; unlock state rides
 * the save file. Achievements unlock either explicitly (a script or system
 * calls {@see self::unlock()}) or automatically, when their declared
 * world-state conditions come true.
 *
 * @package Ichiloto\Engine\Progress
 */
class AchievementManager
{
  /**
   * @var AchievementManager|null The manager bound to the running game scene.
   */
  protected static ?AchievementManager $current = null;
  /**
   * @var array<string, Achievement> The authored achievements, keyed by id.
   */
  protected(set) array $achievements = [];
  /**
   * @var array<string, int> Unlock timestamps, keyed by achievement id.
   */
  protected(set) array $unlocked = [];

  /**
   * AchievementManager constructor.
   *
   * @param Game $game The game.
   * @param GameScene $gameScene The owning game scene.
   */
  public function __construct(
    protected Game $game,
    protected GameScene $gameScene,
  )
  {
    $this->achievements = self::loadDefinitions();
    self::$current = $this;
  }

  /**
   * Returns the manager bound to the running game scene, if any.
   *
   * @return AchievementManager|null The current manager.
   */
  public static function current(): ?AchievementManager
  {
    return self::$current;
  }

  /**
   * Determines whether the project authors any achievements.
   *
   * @return bool True when achievements exist.
   */
  public static function projectHasAchievements(): bool
  {
    return ! empty(self::loadDefinitions());
  }

  /**
   * Loads the project's achievement definitions.
   *
   * @return array<string, Achievement> The achievements, keyed by id.
   */
  public static function loadDefinitions(): array
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'achievements.php');

    if (! file_exists($filename)) {
      return [];
    }

    $entries = require $filename;
    $achievements = [];

    foreach (is_array($entries) ? $entries : [] as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      try {
        $achievement = Achievement::fromArray($entry);
        $achievements[$achievement->id] = $achievement;
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Skipping invalid achievement: %s', $exception->getMessage()));
      }
    }

    return $achievements;
  }

  /**
   * Restores unlock state from a save file.
   *
   * @param array<string, mixed> $data The persisted unlock data.
   * @return void
   */
  public function hydrate(array $data): void
  {
    $this->unlocked = [];

    foreach ($data as $achievementId => $unlockedAt) {
      if (is_string($achievementId) && trim($achievementId) !== '') {
        $this->unlocked[trim($achievementId)] = intval($unlockedAt);
      }
    }
  }

  /**
   * @return array<string, int> The unlock timestamps for persistence.
   */
  public function toArray(): array
  {
    return $this->unlocked;
  }

  /**
   * Determines whether an achievement is unlocked.
   *
   * @param string $achievementId The achievement id.
   * @return bool True when unlocked.
   */
  public function isUnlocked(string $achievementId): bool
  {
    return isset($this->unlocked[trim($achievementId)]);
  }

  /**
   * Unlocks an achievement, announcing and broadcasting it.
   *
   * @param string $achievementId The achievement id.
   * @param int|null $unlockedAt The unlock timestamp; defaults to now.
   * @return bool True when newly unlocked.
   */
  public function unlock(string $achievementId, ?int $unlockedAt = null): bool
  {
    $achievementId = trim($achievementId);
    $achievement = $this->achievements[$achievementId] ?? null;

    if ($achievement === null) {
      Debug::warn(sprintf('Cannot unlock unknown achievement: %s', $achievementId));
      return false;
    }

    if ($this->isUnlocked($achievementId)) {
      return false;
    }

    $this->unlocked[$achievementId] = $unlockedAt ?? time();
    $this->announce($achievement);

    try {
      broadcast($this->game, new AchievementEvent($achievementId));
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Achievement broadcast failed: %s', $exception->getMessage()));
    }

    return true;
  }

  /**
   * Re-evaluates every condition-driven achievement.
   *
   * Called when world state changes, so achievements that watch switches,
   * story events, variables, items, or quests unlock on their own.
   *
   * @return void
   */
  public function evaluateConditionalAchievements(): void
  {
    foreach ($this->achievements as $achievementId => $achievement) {
      if (empty($achievement->conditions) || $this->isUnlocked($achievementId)) {
        continue;
      }

      if (WorldConditionEvaluator::allHold($achievement->conditions, $this->gameScene->gameState, $this->gameScene->party)) {
        $this->unlock($achievementId);
      }
    }
  }

  /**
   * Returns the total number of achievement points earned.
   *
   * @return int The earned points.
   */
  public function earnedPoints(): int
  {
    $points = 0;

    foreach (array_keys($this->unlocked) as $achievementId) {
      $points += $this->achievements[$achievementId]?->points ?? 0;
    }

    return $points;
  }

  /**
   * Announces a newly unlocked achievement.
   *
   * @param Achievement $achievement The unlocked achievement.
   * @return void
   */
  protected function announce(Achievement $achievement): void
  {
    try {
      notify(
        $this->game,
        NotificationChannel::ACHIEVEMENT,
        'Achievement unlocked',
        trim(sprintf('%s %s', $achievement->icon, $achievement->name)),
        NotificationDuration::LONG
      );
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Achievement notification failed: %s', $exception->getMessage()));
    }
  }
}
