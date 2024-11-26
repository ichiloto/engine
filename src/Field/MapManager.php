<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRenderAt;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * The MapManager class is responsible for managing the map.
 *
 * @package Ichiloto\Engine\Field
 */
class MapManager implements CanRenderAt
{
  /**
   * The instance of the MapManager.
   *
   * @var MapManager|null
   */
  protected static ?self $instance = null;
  /**
   * The tile map.
   *
   * @var string[][]
   */
  protected array $tileMap = [];
  /**
   * The collision map.
   *
   * @var int[][]
   */
  protected array $collisionMap = [];
  /**
   * The default collision dictionary.
   *
   * @var array<string, CollisionType>
   */
  protected array $defaultCollisionDictionary = [
    ';' => CollisionType::ENCOUNTER,
    '~' => CollisionType::SOLID,
    '|' => CollisionType::SOLID,
    '-' => CollisionType::SOLID,
    '(' => CollisionType::SOLID,
    ')' => CollisionType::SOLID,
    'x' => CollisionType::SOLID,
    '.' => CollisionType::SOLID,
    '`' => CollisionType::SOLID,
    '#' => CollisionType::SOLID,
    ':' => CollisionType::SOLID,
    '?' => CollisionType::SAVE_POINT,
    'o' => CollisionType::COLLECTABLE,
  ];
  protected int $mapWidth = 0;
  protected int $mapHeight = 0;

  /**
   * The constructor of the MapManager.
   *
   * @param Game $game The game instance.
   */
  protected function __construct(protected Game $game)
  {
  }

  /**
   * Returns the instance of the MapManager.
   *
   * @param Game $game The game instance.
   * @return MapManager The instance of the MapManager.
   */
  public static function getInstance(Game $game): self
  {
    if (!self::$instance) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * Loads the map from a file.
   *
   * @param string $filename The filename of the map.
   * @return MapManager The instance of the MapManager.
   * @throws NotFoundException If the file is not found.
   */
  public function loadMap(string $filename): self
  {
    // Load the tile map from the file
    $this->loadTileMap($filename);
    $this->render();
    return $this;
  }

  /**
   * Determines if the player can move to the specified coordinates.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return bool True if the player can move to the specified coordinates, false otherwise.
   * @throws NotFoundException If the collision type is not found.
   * @throws OutOfBounds If the coordinates are out of bounds.
   */
  public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool
  {
    if ($this->coordinatesAreNotDefined($x, $y)) {
      return false;
    }

    $collisionType = $this->getCollision($x, $y);
    return $collisionType !== CollisionType::SOLID;
  }

  /**
   * Gets the collision type at the specified coordinates.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return CollisionType The collision type.
   * @throws NotFoundException If the collision type is not found.
   * @throws OutOfBounds If the coordinates are out of bounds.
   */
  public function getCollision(int $x, int $y): CollisionType
  {
    if ($this->coordinatesAreNotDefined($x, $y)) {
      return throw new OutOfBounds("Coordinates $x, $y");
    }

    return CollisionType::tryFrom($this->collisionMap[$y][$x]) ?? throw new NotFoundException('Collision type not found.');
  }

  /**
   * Determines if the coordinates are defined in the collision map.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return bool True if the coordinates are defined, false otherwise.
   */
  private function coordinatesAreDefined(int $x, int $y): bool
  {
    if (!isset($this->collisionMap[$y]) || !isset($this->collisionMap[$y][$x])) {
      Debug::warn("Coordinates $x, $y are not defined.");
      return false;
    }

    return true;
  }

  /**
   * Determines if the coordinates are not defined in the collision map.
   *
   * @param int $x The x-coordinate.
   * @param int $y The y-coordinate.
   * @return bool True if the coordinates are not defined, false otherwise.
   */
  private function coordinatesAreNotDefined(int $x, int $y): bool
  {
    return !$this->coordinatesAreDefined($x, $y);
  }

  /**
   * Loads the collision dictionary from a file.
   *
   * @param string $filename The filename of the collision dictionary.
   * @return array<string, CollisionType> The collision dictionary.
   * @throws NotFoundException
   */
  public function loadCollisionDictionary(string $filename): array
  {
    $dictionary = [];

    if (! file_exists($filename) ) {
      throw new NotFoundException("File $filename not found.");
    }

    $dictionary = require $filename;

    if (! is_array($dictionary)) {
      throw new NotFoundException("File $filename does not return an array.");
    }

    if (!empty($dictionary)) {
      foreach ($dictionary as $key => $value) {
        if (! is_string($key) || ! ($value instanceof CollisionType) ) {
          throw new NotFoundException("Invalid dictionary entry: " . gettype($key) . "($key) => " . gettype($value) ."($value)");
        }
      }
    }

    return $dictionary;
  }

  /**
   * Loads the tile map from a file.
   *
   * @param string $filename The filename of the tile map.
   * @return void
   * @throws NotFoundException If the file is not found.
   */
  private function loadTileMap(string $filename): void
  {
    // Load the tile map from the file
    $assetsDirectory = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    $filename = Path::join($assetsDirectory, 'Maps', $filename);

    if (! file_exists($filename) ) {
      throw new NotFoundException("File $filename not found.");
    }

    $map = require $filename;

    if (false === $map) {
      throw new NotFoundException("File $filename does not return an array.");
    }

    $this->tileMap = $map['tile_map'] ?? throw new InvalidArgumentException("tile_map not found in map array of $filename.");

    // Load collision dictionary from file
    $collisionDictionaryFilename = Path::join(Path::getCurrentWorkingDirectory(), 'assets/Maps/collisions.php');
    $dictionary = $this->loadCollisionDictionary($collisionDictionaryFilename);

    // Load the collision map from the tile map
    $this->loadCollisionMap($this->tileMap, $dictionary);
  }

  /**
   * Loads the collision map from a tile map.
   *
   * @param string[] $tileMap The tile map.
   * @param array<string, CollisionType> $dictionary The dictionary that maps tile characters to collision types.
   * @return void
   */
  private function loadCollisionMap(array $tileMap, array $dictionary = []): void
  {
    $this->collisionMap = $this->generateCollisionMap($tileMap, $dictionary);
  }

  /**
   * Generates a collision map from a tile map.
   *
   * @param string[] $tilemap The tile map.
   * @param array<string, CollisionType> $dictionary The dictionary that maps tile characters to collision types.
   * @return int[][] The collision map.
   */
  public function generateCollisionMap(
    array $tilemap,
    array $dictionary = []
  ): array
  {
    if (empty($dictionary)) {
      $dictionary = $this->defaultCollisionDictionary;
    }

    $collisionMap = [];

    foreach ($tilemap as $row) {
      $collisionRow = [];

      foreach (mb_str_split($row) as $tile) {
        $collisionRow[] = $dictionary[$tile]->value ?? CollisionType::SOLID->value;
      }

      $collisionMap[] = $collisionRow;
    }

    return $collisionMap;
  }

  /**
   * Renders the map.
   *
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    foreach ($this->tileMap as $index => $tileMapRow) {
      Console::write($tileMapRow, ($x ?? 0) + 1, ($y ?? 0) + 1 + $index);
    }
  }

  /**
   * Erases the map.
   *
   * @param int|null $x
   * @param int|null $y
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    foreach ($this->tileMap as $index => $tileMapRow) {
      $row = str_repeat(' ', $this->mapWidth);
      Console::write($row, $x, $y + $index);
    }
  }
}