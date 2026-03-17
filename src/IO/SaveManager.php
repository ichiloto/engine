<?php

namespace Ichiloto\Engine\IO;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\IO\Saves\SavedGame;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/**
 * The SaveManager class. Manages the saving of the game.
 *
 * @package Ichiloto\Engine\IO
 */
class SaveManager
{
  protected const string FILE_EXTENSION = 'iedata';
  protected const string FILE_HEADER = 'IED1';
  protected const int DEFAULT_SLOT_COUNT = 5;

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

  public function __construct(
    protected Game $game,
    protected string $saveDirectory = './saves',
    protected string $quickSaveDirectory = './saves/quick',
  )
  {
    $this->saveDirectory = Path::normalize(Path::join(Path::getCurrentWorkingDirectory(), self::DATA_DIRECTORY, $this->saveDirectory));
    $this->quickSaveDirectory = Path::normalize(Path::join(Path::getCurrentWorkingDirectory(), self::DATA_DIRECTORY, $this->quickSaveDirectory));
    $this->ensureDirectoriesExist();
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
    $saveFiles = glob($this->saveDirectory . '/*.' . self::FILE_EXTENSION) ?: [];

    if ($includeQuickSaves) {
      $quickSaveFiles = glob($this->quickSaveDirectory . '/*.' . self::FILE_EXTENSION) ?: [];
      $saveFiles = [...$saveFiles, ...$quickSaveFiles];
    }

    usort(
      $saveFiles,
      static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left)
    );

    return $saveFiles;
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
   * Returns the configured save slots for the current project.
   *
   * @param int $slotCount The number of slots to resolve.
   * @return SaveSlot[] The resolved save slots.
   */
  public function getSaveSlots(int $slotCount = self::DEFAULT_SLOT_COUNT): array
  {
    $slots = [];

    for ($slot = 1; $slot <= $slotCount; $slot++) {
      $path = $this->getSlotPath($slot);
      $slots[] = file_exists($path)
        ? $this->loadSaveFile($path)->slot
        : SaveSlot::empty($slot, $path);
    }

    return $slots;
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
    $savedGame = $this->createSavedGame($scene, $slot);
    $serializedPayload = serialize([
      'slot' => $savedGame->slot,
      'config' => $savedGame->config,
    ]);
    $encodedPayload = gzencode($serializedPayload, 9);

    if ($encodedPayload === false) {
      throw new RuntimeException('Could not encode the save payload.');
    }

    $bytes = file_put_contents($savedGame->slot->path, self::FILE_HEADER . $encodedPayload);

    if ($bytes === false) {
      throw new RuntimeException(sprintf('Could not write save slot %d.', $slot));
    }

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
    if (! file_exists($path)) {
      throw new RuntimeException(sprintf('Save file not found: %s', $path));
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
      throw new RuntimeException(sprintf('Could not read save file: %s', $path));
    }

    if (! str_starts_with($contents, self::FILE_HEADER)) {
      throw new RuntimeException(sprintf('Invalid save file header: %s', $path));
    }

    $compressedPayload = substr($contents, strlen(self::FILE_HEADER));
    $serializedPayload = gzdecode($compressedPayload);

    if ($serializedPayload === false) {
      throw new RuntimeException(sprintf('Could not decode save file: %s', $path));
    }

    $payload = unserialize($serializedPayload, ['allowed_classes' => true]);

    if (! is_array($payload)) {
      throw new RuntimeException(sprintf('Invalid save file payload: %s', $path));
    }

    $slot = $payload['slot'] ?? null;
    $config = $payload['config'] ?? null;

    if (! $slot instanceof SaveSlot || ! $config instanceof GameConfig) {
      throw new RuntimeException(sprintf('Save file payload is incomplete: %s', $path));
    }

    return new SavedGame($slot, $config);
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

    return file_exists($path) ? unlink($path) : false;
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

    return Path::join($this->saveDirectory, sprintf('file-%02d.%s', $slot, self::FILE_EXTENSION));
  }

  /**
   * Creates a serializable save payload from the live game scene.
   *
   * @param GameScene $scene The live game scene.
   * @param int $slot The 1-based save slot.
   * @return SavedGame The created save payload.
   */
  protected function createSavedGame(GameScene $scene, int $slot): SavedGame
  {
    $config = $scene->createSnapshot((int) Time::getTime());
    $leader = $scene->party?->leader;
    $saveSlot = new SaveSlot(
      slot: $slot,
      path: $this->getSlotPath($slot),
      isEmpty: false,
      locationName: $scene->party?->location?->name ?? 'Unknown',
      leaderName: $leader?->name ?? '',
      leaderLevel: $leader?->level ?? 0,
      playTimeSeconds: $config->playTimeSeconds,
      savedAt: time(),
    );

    return new SavedGame($saveSlot, $config);
  }

  /**
   * Creates the save directories if they do not exist yet.
   *
   * @return void
   */
  protected function ensureDirectoriesExist(): void
  {
    foreach ([$this->saveDirectory, $this->quickSaveDirectory] as $directory) {
      if (is_dir($directory)) {
        continue;
      }

      if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create save directory: %s', $directory));
      }
    }
  }
}
