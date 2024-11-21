<?php

namespace Ichiloto\Engine\IO;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;

/**
 * The SaveManager class. Manages the saving of the game.
 *
 * @package Ichiloto\Engine\IO
 */
class SaveManager
{
  /**
   * The data directory path.
   *
   * @var string DATA_DIRECTORY The data directory path.
   */
  const string DATA_DIRECTORY = '.data';

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
    $saveFiles = glob($this->saveDirectory . '/*.iedata');

    if ($includeQuickSaves) {
      $quickSaveFiles = glob($this->quickSaveDirectory . '/*.iedata');
      $saveFiles = [...$saveFiles, ...$quickSaveFiles];
    }

    return $saveFiles;
  }
}