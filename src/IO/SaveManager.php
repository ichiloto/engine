<?php

namespace Ichiloto\Engine\IO;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Exceptions\CorruptSaveException;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\Saves\SavedGame;
use Ichiloto\Engine\IO\Saves\SaveFeatures;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\IO\Saves\SaveFeedback;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\LocalDataFiles;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The SaveManager class. Manages the saving of the game.
 *
 * @package Ichiloto\Engine\IO
 */
class SaveManager
{
  protected const string FILE_EXTENSION = 'iedata';
  protected const string FILE_HEADER = 'IED1';
  protected const int DEFAULT_SLOT_COUNT = 30;
  /**
   * The slot number recorded in quick saves (outside the player-visible range).
   */
  public const int QUICK_SAVE_SLOT = -1;
  /**
   * The slot number recorded in autosaves.
   */
  public const int AUTO_SAVE_SLOT = -2;
  protected const string QUICK_SAVE_NAME = 'quick';
  protected const string AUTO_SAVE_NAME = 'auto';
  private const int ORDER_UNITS_PER_SECOND = 1_000_000;

  /**
   * The data directory path.
   *
   * @var string DATA_DIRECTORY The data directory path.
   */
  public const string DATA_DIRECTORY = '.data';

  /**
   * The instance of the SaveManager class.
   *
   * @var SaveManager|null $instance The instance of the SaveManager class.
   */
  protected static ?SaveManager $instance = null;

  /** The single compatibility pipeline shared by every save surface. */
  protected SaveCompatibilityPipeline $compatibilityPipeline;
  private ?string $isolatedDataRoot = null;

  public function __construct(
    protected Game $game,
    protected string $saveDirectory = './saves',
    protected string $quickSaveDirectory = './saves/quick',
    ?SaveCompatibilityManifest $compatibilityManifest = null,
  )
  {
    $this->isolatedDataRoot = $game->hasIsolatedDataRoot() ? $game->getDataRoot() : null;
    $this->saveDirectory = Path::normalize(Path::join($game->getDataRoot(), $this->saveDirectory));
    $this->quickSaveDirectory = Path::normalize(Path::join($game->getDataRoot(), $this->quickSaveDirectory));
    $this->validateStoragePath($this->saveDirectory);
    $this->validateStoragePath($this->quickSaveDirectory);
    $compatibilityManifest ??= SaveCompatibilityManifest::fromProjectRoot(Path::getCurrentWorkingDirectory());
    $this->compatibilityPipeline = new SaveCompatibilityPipeline($compatibilityManifest,
      projectStateRegistry: $game->getProjectStateRegistry());
    $this->diagnoseStorageAvailability();
  }

  /**
   * Returns the instance of the SaveManager class.
   *
   * @param Game $game The game instance.
   * @return SaveManager The instance of the SaveManager class.
   */
  public static function getInstance(Game $game): self
  {
    if (!self::$instance) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * Get a list of all the saved game files in the save directory.
   *
   * @param bool $includeQuickSaves Whether to include quick saves.
   * @return string[] The list of saved game files.
   */
  public function getSaveFiles(bool $includeQuickSaves = false): array
  {
    $this->validateStoragePath($this->saveDirectory);
    $this->validateStoragePath($this->quickSaveDirectory);
    $saveFiles = glob($this->saveDirectory . '/*.' . self::FILE_EXTENSION) ?: [];

    if ($includeQuickSaves) {
      $quickSaveFiles = glob($this->quickSaveDirectory . '/*.' . self::FILE_EXTENSION) ?: [];
      $saveFiles = [...$saveFiles, ...$quickSaveFiles];
    }

    foreach ($saveFiles as $path) { $this->validateStoragePath($path); }

    $orders = [];
    foreach ($saveFiles as $path) { $orders[$path] = $this->getSaveOrder($path); }
    usort($saveFiles, static fn(string $left, string $right): int =>
      ($orders[$right] <=> $orders[$left]) ?: strcmp($left, $right));

    return $saveFiles;
  }

  /** Enabled discovery retains invalid records so a Load selector can diagnose them. */
  public function getEnabledSaveFiles(bool $includeQuickSaves = false): array
  {
    return array_values(array_filter($this->getSaveFiles($includeQuickSaves), $this->isEnabledSavePath(...)));
  }

  public function hasEnabledSaveFiles(bool $includeQuickSaves = false): bool
  {
    return $this->getEnabledSaveFiles($includeQuickSaves) !== [];
  }

  /**
   * Returns whether any save files currently exist.
   *
   * @param bool $includeQuickSaves Whether to include quick saves.
   * @return bool True when at least one save file exists.
   */
  public function hasSaveFiles(bool $includeQuickSaves = false): bool
  {
    return $this->getSaveFiles($includeQuickSaves) !== [];
  }

  /**
   * Returns the newest save-file path, if one exists.
   *
   * @param bool $includeQuickSaves Whether to include quick saves.
   * @return string|null The newest save-file path.
   */
  public function getLatestSaveFile(bool $includeQuickSaves = false): ?string
  {
    return $this->getSaveFiles($includeQuickSaves)[0] ?? null;
  }

  /**
   * Returns the newest save-file path that can still be decoded.
   *
   * @param bool $includeQuickSaves Whether to include quick saves.
   * @return string|null The newest loadable save-file path.
   */
  public function getLatestLoadableSaveFile(bool $includeQuickSaves = false): ?string
  {
    foreach ($this->getEnabledSaveFiles($includeQuickSaves) as $path) {
      if ($this->canLoadSaveFile($path)) {
        return $path;
      }
    }

    return null;
  }

  /**
   * Returns the project's manual-slot capacity, shared by Save and Load.
   * This is project configuration, not player settings or saved campaign data.
   */
  public function getManualSlotCount(): int
  {
    $count = ConfigStore::has(ProjectConfig::class)
      ? ConfigStore::get(ProjectConfig::class)->get('saves.manual_slots', self::DEFAULT_SLOT_COUNT)
      : self::DEFAULT_SLOT_COUNT;
    if (!is_int($count) || $count < 1) {
      throw new InvalidArgumentException('Project saves.manual_slots must be a positive integer.');
    }
    return $count;
  }

  /**
   * Returns the configured save slots for the current project.
   *
   * @param int|null $slotCount Explicit inspection range; null uses project capacity.
   * @return SaveSlot[] The resolved save slots.
   */
  public function getSaveSlots(?int $slotCount = null): array
  {
    $slotCount ??= $this->getManualSlotCount();
    $slots = [];

    for ($slot = 1; $slot <= $slotCount; $slot++) {
      $path = $this->getSlotPath($slot);

      if (! file_exists($path)) {
        $slots[] = SaveSlot::empty($slot, $path);
        continue;
      }

      $slots[] = $this->inspectSaveSlot($slot, $path);
    }

    return $slots;
  }

  /**
   * Read-only load catalog: an existing enabled Quick Save first, then one
   * logical Autosave when enabled, then manual files. Legacy autosaves remain intact.
   * The newest loadable autosave wins; if none decode, show the newest failure.
   * @return SaveSlot[]
   */
  public function getLoadSlots(?int $slotCount = null): array
  {
    $slots = $this->getSaveSlots($slotCount);
    if (SaveFeatures::isAutosaveEnabled()) {
      $candidates = array_values(array_filter($this->getSaveFiles(true), fn(string $path): bool =>
        dirname($path) === $this->quickSaveDirectory && $this->getDestinationSlot($path) === self::AUTO_SAVE_SLOT));
      $path = array_find($candidates, $this->canLoadSaveFile(...)) ?? ($candidates[0] ?? null);
      array_unshift($slots, $path === null
        ? SaveSlot::empty(self::AUTO_SAVE_SLOT, $this->getQuickSavePath(self::AUTO_SAVE_NAME))
        : $this->inspectSaveSlot(self::AUTO_SAVE_SLOT, $path));
    }
    $quick = $this->getQuickSavePath(self::QUICK_SAVE_NAME);
    if (SaveFeatures::isQuickSaveEnabled() && file_exists($quick)) {
      array_unshift($slots, $this->inspectSaveSlot(self::QUICK_SAVE_SLOT, $quick));
    }
    return $slots;
  }

  private function isEnabledSavePath(string $path): bool
  {
    return match ($this->getDestinationSlot($path)) {
      self::AUTO_SAVE_SLOT => SaveFeatures::isAutosaveEnabled(),
      self::QUICK_SAVE_SLOT => SaveFeatures::isQuickSaveEnabled(),
      default => true,
    };
  }

  private function inspectSaveSlot(int $slot, string $path): SaveSlot
  {
    try {
      return $this->loadSaveFile($path)->slot;
    } catch (Throwable $throwable) {
      try {
        Debug::warn(sprintf('Skipping incompatible save slot %d (%s): %s', $slot, $path, $throwable->getMessage()));
      } catch (Throwable) {
      }
      return SaveSlot::incompatible($slot, $path, SaveFeedback::describeLoadFailure($throwable));
    }
  }

  /**
   * Saves the active game-scene state into the selected slot.
   *
   * @param GameScene $scene The live game scene.
   * @param int $slot The 1-based save slot.
   * @return SaveSlot The saved slot summary.
   */
  public function save(GameScene $scene, int $slot): SaveSlot
  {
    return $this->writeSave($scene, $slot, $this->getSlotPath($slot));
  }

  /**
   * Writes a quick save, overwriting the previous one.
   *
   * Quick saves live beside the numbered slots in their own directory and
   * never consume a player-visible slot.
   *
   * @param GameScene $scene The live game scene.
   * @return SaveSlot The saved slot summary.
   */
  public function quickSave(GameScene $scene): SaveSlot
  {
    if (!SaveFeatures::isQuickSaveEnabled()) { throw new RuntimeException('Quick Save is disabled by this project.'); }
    return $this->writeSave($scene, self::QUICK_SAVE_SLOT, $this->getQuickSavePath(self::QUICK_SAVE_NAME));
  }

  /** Writes the single Autosave atomically; only the developer chooses when. */
  public function autoSave(GameScene $scene): SaveSlot
  {
    if (!SaveFeatures::isAutosaveEnabled()) { throw new RuntimeException('Autosave is disabled by this project.'); }
    return $this->writeSave($scene, self::AUTO_SAVE_SLOT, $this->getQuickSavePath(self::AUTO_SAVE_NAME));
  }

  /**
   * Returns the path for a named file in the quick-save directory.
   *
   * @param string $name The file name without its extension.
   * @return string The absolute path.
   */
  public function getQuickSavePath(string $name): string
  {
    return $this->validateStoragePath(Path::join($this->quickSaveDirectory, sprintf('%s.%s', $name, self::FILE_EXTENSION)));
  }

  /**
   * Serializes the live scene to the given path.
   *
   * @param GameScene $scene The live game scene.
   * @param int $slot The slot number recorded in the payload.
   * @param string $path The destination path.
   * @return SaveSlot The saved slot summary.
   */
  protected function writeSave(GameScene $scene, int $slot, string $path): SaveSlot
  {
    $this->validateStoragePath($path);
    if ($scene->hasUnstableEventSession()) {
      throw new ActiveEventSaveException(
        'Saving is unavailable while a story event is in progress. Finish the event first.'
      );
    }

    $savedGame = $this->createSavedGame($scene, $slot, $path);
    $envelope = $this->compatibilityPipeline->createEnvelope(
      $savedGame->slot,
      $savedGame->config
    );
    $envelope['saveOrder'] = $this->getNextSaveOrder();
    $serializedPayload = serialize($envelope);
    $encodedPayload = gzencode($serializedPayload, 9);

    if ($encodedPayload === false) {
      throw new RuntimeException('Could not encode the save payload.');
    }

    LocalDataFiles::writeAtomically($savedGame->slot->path, self::FILE_HEADER . $encodedPayload);

    return $savedGame->slot;
  }

  /**
   * Loads a saved game from the specified slot.
   *
   * @param int $slot The 1-based slot number.
   * @return SavedGame The decoded saved game.
   */
  public function loadSlot(int $slot): SavedGame
  {
    return $this->loadSaveFile($this->getSlotPath($slot));
  }

  /**
   * Loads a saved game from an arbitrary save-file path.
   *
   * @param string $path The save-file path.
   * @return SavedGame The decoded saved game.
   */
  public function loadSaveFile(string $path): SavedGame
  {
    $this->validateStoragePath($path);
    if (! file_exists($path)) {
      throw new RuntimeException(sprintf('Save file not found: %s', $path));
    }

    return $this->compatibilityPipeline->load($this->decodeSavePayload($path), $path, $this->getDestinationSlot($path));
  }

  private function getDestinationSlot(string $path): ?int
  {
    $name = pathinfo($path, PATHINFO_FILENAME);

    if ($name === self::QUICK_SAVE_NAME) {
      return self::QUICK_SAVE_SLOT;
    }

    if ($name === self::AUTO_SAVE_NAME || preg_match('/^' . self::AUTO_SAVE_NAME . '-[0-9]+$/D', $name)) {
      return self::AUTO_SAVE_SLOT;
    }

    if (preg_match('/^file-([0-9]+)$/D', $name, $matches)) {
      $slot = filter_var(ltrim($matches[1], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

      return $slot === false ? null : $slot;
    }

    return null;
  }

  /**
   * Returns whether the specified save file can still be decoded.
   *
   * @param string $path The save-file path.
   * @return bool True when the file can be loaded.
   */
  public function canLoadSaveFile(string $path): bool
  {
    try {
      $slot = $this->loadSaveFile($path)->slot;
      return !$slot->isEmpty && $slot->isLoadable;
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * Deletes the specified save slot, if it exists.
   *
   * @param int $slot The 1-based slot number.
   * @return bool True when a file was deleted.
   */
  public function deleteSlot(int $slot): bool
  {
    $path = $this->getSlotPath($slot);
    try {
      return LocalDataFiles::runFileOperation(static fn(): bool => file_exists($path) && unlink($path));
    } catch (RuntimeException $exception) {
      Debug::warn(sprintf('Could not delete save slot %d: %s', $slot, $exception->getMessage()));
      return false;
    }
  }

  /**
   * Returns the absolute path of a save slot.
   *
   * @param int $slot The 1-based slot number.
   * @return string The absolute save-file path.
   */
  public function getSlotPath(int $slot): string
  {
    if ($slot < 1) {
      throw new RuntimeException('Save slots are 1-based.');
    }

    return $this->validateStoragePath(Path::join($this->saveDirectory, sprintf('file-%02d.%s', $slot, self::FILE_EXTENSION)));
  }

  private function validateStoragePath(string $path): string
  {
    return $this->isolatedDataRoot === null ? $path : LaunchDataRoot::validatePath($this->isolatedDataRoot, $path);
  }

  /** Scalar-only inspection must not instantiate saved gameplay objects. */
  private function getSaveOrder(string $path): int
  {
    try {
      $payload = LocalDataFiles::runFileOperation(fn() =>
        unserialize($this->decodeSavePayload($path), ['allowed_classes' => false]));
      if (is_array($payload) && is_int($payload['saveOrder'] ?? null) && $payload['saveOrder'] > 0) {
        return $payload['saveOrder'];
      }
    } catch (Throwable) {
      // Corrupt and unmarked legacy files still have a deterministic position.
    }
    clearstatcache(true, $path);
    $modified = LocalDataFiles::runFileOperation(static fn() => filemtime($path));
    return min(max(0, (int)$modified), intdiv(PHP_INT_MAX, self::ORDER_UNITS_PER_SECOND))
      * self::ORDER_UNITS_PER_SECOND;
  }

  private function getNextSaveOrder(): int
  {
    $previous = 0;
    foreach ($this->getSaveFiles(true) as $path) {
      $previous = max($previous, $this->getSaveOrder($path));
    }
    if ($previous === PHP_INT_MAX) {
      throw new RuntimeException('The persisted save order has reached its supported limit.');
    }
    return max($previous + 1, (int)floor(microtime(true) * self::ORDER_UNITS_PER_SECOND));
  }

  /**
   * Creates a serializable save payload from the live game scene.
   *
   * @param GameScene $scene The live game scene.
   * @param int $slot The 1-based save slot.
   * @return SavedGame The created save payload.
   */
  protected function createSavedGame(GameScene $scene, int $slot, ?string $path = null): SavedGame
  {
    $config = $scene->createSnapshot((int) Time::getTime());
    $leader = $scene->party?->leader;
    $saveSlot = new SaveSlot(
      slot: $slot,
      path: $path ?? $this->getSlotPath($slot),
      isEmpty: false,
      locationName: $scene->party?->location?->name ?? 'Unknown',
      leaderName: $leader?->name ?? '',
      leaderLevel: $leader?->level ?? 0,
      playTimeSeconds: $config->playTimeSeconds,
      savedAt: time(),
    );

    return new SavedGame($saveSlot, $config);
  }

  /** Report an unavailable save location without writing to it during startup. */
  protected function diagnoseStorageAvailability(): void
  {
    $diagnostics = [];
    foreach ([$this->saveDirectory, $this->quickSaveDirectory] as $target) {
      try {
        $directory = $target;
        while (! LocalDataFiles::runFileOperation(static fn(): bool => is_dir($directory))) {
          if (LocalDataFiles::runFileOperation(static fn(): bool => file_exists($directory))) {
            $diagnostics["$directory is not a directory"] = true;
            continue 2;
          }
          $parent = dirname($directory);
          if ($parent === $directory) { break; }
          $directory = $parent;
        }
        if (! LocalDataFiles::runFileOperation(static fn(): bool => is_writable($directory))) {
          $diagnostics["$directory is not writable"] = true;
        }
      } catch (RuntimeException $exception) {
        $diagnostics[$exception->getMessage()] = true;
      }
    }
    foreach (array_keys($diagnostics) as $reason) {
      Debug::warn("Saving is unavailable: $reason.");
    }
  }

  /**
   * Decodes the binary payload stored in a save file.
   *
   * @param string $path The save-file path.
   * @return string The decoded serialized payload.
   */
  protected function decodeSavePayload(string $path): string
  {
    try {
      $contents = LocalDataFiles::runFileOperation(static fn() => file_get_contents($path));
    } catch (RuntimeException $error) {
      throw new CorruptSaveException(sprintf('Could not read save file: %s', $path), previous: $error);
    }

    if ($contents === false) {
      throw new CorruptSaveException(sprintf('Could not read save file: %s', $path));
    }

    if (! str_starts_with($contents, self::FILE_HEADER)) {
      throw new CorruptSaveException(sprintf('Invalid save file header: %s', $path));
    }

    $compressedPayload = substr($contents, strlen(self::FILE_HEADER));
    try {
      $serializedPayload = LocalDataFiles::runFileOperation(static fn() => gzdecode($compressedPayload));
    } catch (RuntimeException $error) {
      throw new CorruptSaveException(sprintf('Could not decode save file: %s', $path), previous: $error);
    }

    if ($serializedPayload === false) {
      throw new CorruptSaveException(sprintf('Could not decode save file: %s', $path));
    }

    return $serializedPayload;
  }
}
