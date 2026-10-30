<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Events\Interpreter\EventPlayedDialoguePresentationInterface;
use Ichiloto\Engine\Field\Enumerations\SkitTrigger;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ActorStore;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Manages portrait conversations ("skits") prompted during exploration or selected by story scripts.
 *
 * Skits are authored one per file under `assets/Data/Skits/*.php`:
 *
 * ```php
 * return [
 *   'id' => 'breakfast-banter',
 *   'title' => 'Breakfast Banter',
 *   'trigger' => 'prompt',             // script selects play_skit only; prompt is the existing default
 *   'sounds' => ['open' => 'DeviceOn', 'close' => 'DeviceOff'], // optional reusable SFX cues
 *   'where' => 'happyville/home',      // optional map gate; omit for anywhere
 *   'backdrop' => 'Graphics/Skits/Home/Kitchen.png', // optional; only when the content needs this setting
 *   'conditions' => [ …trigger conditions… ],
 *   'beats' => [
 *     ['speaker' => 'Kaelion', 'text' => '…'],
 *     ['speaker' => 'Liora', 'text' => '…'],
 *   ],
 * ];
 * ```
 *
 * A persistent field prompt shows the next playable skit. Playing one shows
 * its beats as a dialogue exchange and
 * records `skit_seen:<id>` so it never replays. Its graphical stage shows the
 * backdrop of the place it plays in ({@see SkitBackdrop}).
 *
 * @package Ichiloto\Engine\Field
 */
class SkitManager
{
  /**
   * @var array<string, array<string, mixed>> The authored skits, keyed by id.
   */
  protected(set) array $skits = [];
  public private(set) bool $isPlaying = false;

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
        if (!is_array($skit) || !is_string($skit['id'] ?? null)
          || trim($skit['id']) === '' || str_contains($skit['id'], "\0")) {
          throw new InvalidArgumentException('A Skit requires a non-empty string id.');
        }
        $id = trim($skit['id']);
        if (array_key_exists($id, $skits)) {
          throw new InvalidArgumentException("Skit id '{$id}' is already defined by another file.");
        }
        if (!is_array($skit['beats'] ?? null) || !array_is_list($skit['beats']) || $skit['beats'] === []) {
          throw new InvalidArgumentException("Skit {$id} requires a non-empty list of dialogue beats.");
        }
        foreach ($skit['beats'] as $index => $beat) {
          if (!is_array($beat) || !is_string($beat['text'] ?? null)) {
            throw new InvalidArgumentException("Skit {$id} beat {$index} requires text.");
          }
        }
        SkitPlaybackDefinition::fromArray($skit, "Skit {$id}");
        $skits[$id] = $skit;
      } catch (Throwable $exception) {
        throw new RuntimeException(sprintf('Invalid Skit file %s: %s', $filename, $exception->getMessage()),
          previous: $exception);
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
      fn(array $skit, string $skitId): bool => SkitPlaybackDefinition::fromArray($skit, "Skit {$skitId}")->trigger === SkitTrigger::PROMPT
        && $this->isAvailable($skitId, $skit),
      ARRAY_FILTER_USE_BOTH
    );
  }

  /** Reads the same live availability/order used by playback, without consuming it. */
  public function getAvailablePrompt(): ?SkitPrompt
  {
    if ($this->isPlaying) { return null; }
    foreach ($this->availableSkits() as $skitId => $skit) {
      return new SkitPrompt($skitId, strval($skit['title'] ?? $skitId));
    }
    return null;
  }

  /**
   * Plays the first available skit.
   *
   * @return bool True when a skit played.
   */
  public function playNextAvailableSkit(): bool
  {
    if ($this->isPlaying) { return false; }
    foreach ($this->availableSkits() as $skitId => $skit) {
      $this->isPlaying = true;
      try { $this->play($skitId, $skit); }
      finally { $this->isPlaying = false; }
      return true;
    }

    return false;
  }

  /**
   * Opens the selected current Skit as a cooperative event operation. A seen
   * Skit completes immediately; missing or gated content fails without consuming it.
   */
  public function beginSkitPlayback(string $skitId, EventPlayedDialoguePresentationInterface $presentation): ?SkitPlaybackOperation
  {
    $skit = $this->skits[$skitId] ?? throw new RuntimeException("Skit '{$skitId}' does not exist.");
    if ($this->isPlaying) { throw new RuntimeException('Another Skit already owns conversation playback.'); }
    if ($this->gameScene->gameState->hasStoryEvent('skit_seen:' . $skitId)) { return null; }
    if (!$this->isAvailable($skitId, $skit)) {
      throw new RuntimeException("Skit '{$skitId}' is unavailable at the current map or story state.");
    }
    $plan = $this->preparePlaybackPlan($skitId, $skit);
    $game = $this->gameScene->getGame();
    $operation = new SkitPlaybackOperation($plan, $presentation, isset($game->audioManager) ? $game->audioManager : null,
      fn(): bool => $game->hasStopped() || $this->gameScene->isStopping,
      function (bool $completed) use ($game, $skitId): void {
        try {
          if ($completed && !$game->hasStopped() && !$this->gameScene->isStopping) {
            $this->gameScene->gameState->recordStoryEvent('skit_seen:' . $skitId);
          }
        } finally {
          $this->isPlaying = false;
        }
      });
    $this->isPlaying = true;
    return $operation;
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
    $plan = $this->preparePlaybackPlan($skitId, $skit);
    $game = $this->gameScene->getGame();
    $audio = isset($game->audioManager) ? $game->audioManager : null;
    $playback = new DialoguePlayback($audio);
    $playback->beginConversation();
    try {
      if (isset($plan->sounds['open'])) { $audio?->playSoundEffect($plan->sounds['open']); }
      foreach ($plan->beats as $beat) {
        if ($game->hasStopped()) { return; }
        $playback->beginLine($beat['voicePath'], $plan->musicDuck);
        try { $this->showBeat($beat, $plan->speed, $playback); }
        finally { $playback->finishLine(); }
      }
      if (!$game->hasStopped()) { $this->gameScene->gameState->recordStoryEvent('skit_seen:' . $skitId); }
    } finally {
      try { $playback->finishConversation(); }
      finally {
        if (!$game->hasStopped() && isset($plan->sounds['close'])) { $audio?->playSoundEffect($plan->sounds['close']); }
      }
    }
  }

  protected function preparePlaybackPlan(string $skitId, array $skit): SkitPlaybackPlan
  {
    $definition = SkitPlaybackDefinition::fromArray($skit, "Skit {$skitId}");
    $sounds = $definition->sounds;
    $speed = is_numeric($skit['speed'] ?? null) ? floatval($skit['speed']) : dialogue_speed();
    $assets = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    $catalogue = $this->loadDialoguePresentation($assets);
    $actors = ConfigStore::has(ActorStore::class) ? ConfigStore::get(ActorStore::class) : null;
    if (! $actors instanceof ActorStore) {
      $actors = new ActorStore(Path::join($assets, 'Data', 'Actors'));
    }
    $duck = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('audio.voice_music_duck', 1.0) : 1.0;
    $duck = is_numeric($duck) ? (float) $duck : 1.0;

    $participants = [];
    foreach ((array) $skit['beats'] as $beat) {
      if (!is_array($beat)) { continue; }
      $speaker = SkitSpeaker::getFromBeat($beat, $actors);
      if ($speaker->actorId !== null) {
        $participants[$speaker->actorId] = ['actorId' => $speaker->actorId, 'name' => $speaker->name, 'emotion' => 'Neutral'];
      }
    }
    $location = $this->gameScene->party->location;
    $locationName = $location?->name ?? '';
    $locationRegion = $location?->region ?? '';
    $locationLabel = $locationName === $locationRegion || $locationRegion === '' ? $locationName : $locationName . ' - ' . $locationRegion;
    $backdrop = $this->resolveBackdrop($skitId, $skit, $assets);

    $beats = [];
    foreach ((array) $skit['beats'] as $beat) {
      if (!is_array($beat)) { continue; }
      $speaker = SkitSpeaker::getFromBeat($beat, $actors);
      foreach ([...$speaker->notices, ...$speaker->errors] as $diagnostic) { Debug::warn("Skit $skitId: $diagnostic"); }
      $presentation = SkitBeatPresentation::getFromBeat($assets, $skitId, $beat, $catalogue, $speaker->actorId);
      if ($speaker->actorId !== null) { $participants[$speaker->actorId]['emotion'] = $presentation->emotion; }
      $beats[] = [...$beat, 'text' => strval($beat['text'] ?? ''), 'speaker' => $speaker->name,
        'emotion' => $presentation->emotion, 'voicePath' => $presentation->voicePath,
        'presentation' => new DialogueContext($speaker->actorId, $presentation->emotion, $skitId,
          strval($skit['title'] ?? $skitId), $locationLabel, array_values($participants), $backdrop)];
    }
    if ($beats === []) { throw new RuntimeException("Skit '{$skitId}' requires at least one dialogue beat."); }
    return new SkitPlaybackPlan($skitId, $beats, $speed, $duck, $sounds);
  }

  protected function showBeat(array $beat, float $speed, DialoguePlayback $playback): void
  {
    show_text(strval($beat['text'] ?? ''), strval($beat['speaker'] ?? ''),
      charactersPerSecond: $speed, playback: $playback, presentation: $beat['presentation'] ?? null);
  }

  /**
   * The backdrop the skit shows here: its own, the current map's or the map's region's. A skit with none to show
   * is reported with the skit and the map, so the missing art is found, and plays on the theme's backing.
   */
  protected function resolveBackdrop(string $skitId, array $skit, string $assets): ?string
  {
    try { $regions = RegionCatalog::load($assets); }
    catch (Throwable $exception) {
      Debug::warn('Regions could not load: ' . $exception->getMessage());
      $regions = new RegionCatalog();
    }
    $backdrop = SkitBackdrop::resolve($skit, $this->gameScene->mapManager?->skitBackdropBinding ?? [], $regions);
    $absence = $backdrop->describeAbsence($skitId, $this->gameScene->currentMapId);
    if ($absence !== null) { Debug::warn($absence); }
    return $backdrop->asset;
  }

  protected function loadDialoguePresentation(string $assets): DialoguePresentationCatalog
  {
    try { return DialoguePresentationCatalog::load($assets); }
    catch (Throwable $exception) { Debug::warn('Dialogue presentation catalogue could not load: ' . $exception->getMessage()); }
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
