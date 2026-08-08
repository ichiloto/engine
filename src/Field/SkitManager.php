<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Manages Tales-style optional conversations ("skits").
 *
 * Skits are authored one per file under `assets/Data/Skits/*.php`:
 *
 * ```php
 * return [
 *   'id' => 'breakfast-banter',
 *   'title' => 'Breakfast Banter',
 *   'where' => 'happyville/home',      // optional map gate; omit for anywhere
 *   'conditions' => [ …trigger conditions… ],
 *   'beats' => [
 *     ['speaker' => 'Kaelion', 'text' => '…'],
 *     ['speaker' => 'Liora', 'text' => '…'],
 *   ],
 * ];
 * ```
 *
 * When a skit becomes available a notification invites the player to press
 * the skit key; playing one shows its beats as a dialogue exchange and
 * records `skit_seen:<id>` so it never replays.
 *
 * @package Ichiloto\Engine\Field
 */
class SkitManager
{
  /**
   * @var array<string, array<string, mixed>> The authored skits, keyed by id.
   */
  protected(set) array $skits = [];
  /**
   * @var string[] Skit ids already announced this session (avoids notification spam).
   */
  protected array $announced = [];

  /**
   * SkitManager constructor.
   *
   * @param GameScene $gameScene The owning game scene.
   */
  public function __construct(protected GameScene $gameScene)
  {
    $this->skits = self::loadSkits();
  }

  /**
   * Loads every authored skit.
   *
   * @return array<string, array<string, mixed>> The skits, keyed by id.
   */
  public static function loadSkits(): array
  {
    $directory = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'Skits');

    if (! is_dir($directory)) {
      return [];
    }

    $skits = [];

    foreach (glob(Path::join($directory, '*.php')) ?: [] as $filename) {
      try {
        $skit = require $filename;
        $id = is_array($skit) ? trim(strval($skit['id'] ?? '')) : '';

        if ($id !== '' && ! empty($skit['beats'])) {
          $skits[$id] = $skit;
        }
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Skipping invalid skit %s: %s', basename($filename), $exception->getMessage()));
      }
    }

    return $skits;
  }

  /**
   * Returns the skits available right now.
   *
   * @return array<string, array<string, mixed>> The available skits, keyed by id.
   */
  public function availableSkits(): array
  {
    return array_filter(
      $this->skits,
      fn(array $skit, string $skitId): bool => $this->isAvailable($skitId, $skit),
      ARRAY_FILTER_USE_BOTH
    );
  }

  /**
   * Announces newly available skits (map entry, flag changes).
   *
   * @return void
   */
  public function announceAvailableSkits(): void
  {
    foreach ($this->availableSkits() as $skitId => $skit) {
      if (in_array($skitId, $this->announced, true)) {
        continue;
      }

      $this->announced[] = $skitId;

      try {
        notify(
          $this->gameScene->getGame(),
          NotificationChannel::INFO,
          'Skit available',
          sprintf("%s\nPress T to watch.", strval($skit['title'] ?? $skitId)),
          NotificationDuration::LONG
        );
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Skit notification failed: %s', $exception->getMessage()));
      }
    }
  }

  /**
   * Plays the first available skit.
   *
   * @return bool True when a skit played.
   */
  public function playNextAvailableSkit(): bool
  {
    foreach ($this->availableSkits() as $skitId => $skit) {
      $this->play($skitId, $skit);
      return true;
    }

    return false;
  }

  /**
   * Plays one skit and marks it seen.
   *
   * @param string $skitId The skit id.
   * @param array<string, mixed> $skit The skit definition.
   * @return void
   */
  protected function play(string $skitId, array $skit): void
  {
    $speed = is_numeric($skit['speed'] ?? null) ? floatval($skit['speed']) : dialogue_speed();

    foreach ((array) $skit['beats'] as $beat) {
      if (is_array($beat)) {
        show_text(
          strval($beat['text'] ?? ''),
          strval($beat['speaker'] ?? ''),
          charactersPerSecond: $speed
        );
      }
    }

    $this->gameScene->gameState->recordStoryEvent(sprintf('skit_seen:%s', $skitId));
  }

  /**
   * Determines whether a skit is available.
   *
   * @param string $skitId The skit id.
   * @param array<string, mixed> $skit The skit definition.
   * @return bool True when the skit can play here and now.
   */
  protected function isAvailable(string $skitId, array $skit): bool
  {
    $gameState = $this->gameScene->gameState;

    if ($gameState->hasStoryEvent(sprintf('skit_seen:%s', $skitId))) {
      return false;
    }

    $where = trim(strval($skit['where'] ?? ''));

    if ($where !== '' && strcasecmp($where, $this->gameScene->currentMapId) !== 0) {
      return false;
    }

    foreach ((array) ($skit['conditions'] ?? []) as $condition) {
      if (! is_array($condition)) {
        continue;
      }

      $name = trim(strval($condition['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      $result = match (strval($condition['type'] ?? '')) {
        'switch' => $gameState->getSwitch($name) === (bool) ($condition['value'] ?? true),
        'event' => $gameState->hasStoryEvent($name),
        'quest' => \Ichiloto\Engine\Quests\QuestManager::current()?->questStatusMatches($name, strval($condition['status'] ?? 'completed')) ?? false,
        default => true,
      };

      if (($condition['negate'] ?? false) ? $result : ! $result) {
        return false;
      }
    }

    return true;
  }
}
