<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
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
        $skitKeys = (new InputBindings())->describeKeys('skit');
        $highlightedKeys = Color::apply($skitKeys, Color::YELLOW);

        notify(
          $this->gameScene->getGame(),
          NotificationChannel::INFO,
          'Skit available',
          sprintf("%s\nPress %s to watch.", strval($skit['title'] ?? $skitId), $highlightedKeys),
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
    $game = $this->gameScene->getGame();
    $playback = new DialoguePlayback(isset($game->audioManager) ? $game->audioManager : null);
    $assets = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    $catalogue = $this->loadDialoguePresentation($assets);
    $duck = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('audio.voice_music_duck', 1.0) : 1.0;
    $duck = is_numeric($duck) ? (float) $duck : 1.0;

    try {
      foreach ((array) $skit['beats'] as $beat) {
        if ($game->hasStopped()) {
          return;
        }
        if (is_array($beat)) {
          $presentation = SkitBeatPresentation::getFromBeat($assets, $skitId, $beat, $catalogue);
          $playback->beginLine($presentation->voicePath, $duck);
          try {
            $this->showBeat([...$beat, 'emotion' => $presentation->emotion], $speed, $playback);
          } finally {
            $playback->finishLine();
          }
        }
      }
      if (! $game->hasStopped()) {
        $this->gameScene->gameState->recordStoryEvent(sprintf('skit_seen:%s', $skitId));
      }
    } finally {
      $playback->finishLine();
    }
  }

  protected function showBeat(array $beat, float $speed, DialoguePlayback $playback): void
  {
    show_text(strval($beat['text'] ?? ''), strval($beat['speaker'] ?? ''),
      charactersPerSecond: $speed, playback: $playback);
  }

  protected function loadDialoguePresentation(string $assets): DialoguePresentationCatalog
  {
    $filename = Path::join($assets, DialoguePresentationCatalog::FILE);
    if (is_file($filename)) {
      try {
        $catalogue = require $filename;
        if ($catalogue instanceof DialoguePresentationCatalog) {
          return $catalogue;
        }
        Debug::warn('Invalid dialogue presentation catalogue; using Neutral skit emotions.');
      } catch (Throwable $exception) {
        Debug::warn('Dialogue presentation catalogue could not load: ' . $exception->getMessage());
      }
    }
    return new DialoguePresentationCatalog();
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

    return WorldConditionEvaluator::allHold(
      (array) ($skit['conditions'] ?? []),
      $gameState,
      $this->gameScene->party
    );
  }
}
