<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRenderAt;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\PartyLocation as MapLocation;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use voku\helper\ASCII;

/**
 * The MapManager class is responsible for managing the map.
 *
 * @package Ichiloto\Engine\Field
 */
class MapManager implements CanRenderAt
{
  /**
   * @var MapManager|null The instance of the MapManager.
   */
  protected static ?self $instance = null;
  /**
   * @var string[] The tile map.
   */
  protected array $tileMap = [];
  /**
   * The collision map.
   *
   * @var int[][]
   */
  protected array $collisionMap = [];
  /**
   * @var array<string, CollisionType> The default collision dictionary.
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
  /**
   * @var int The width of the map.
   */
  protected int $mapWidth = 0;
  /**
   * @var int The height of the map.
   */
  protected int $mapHeight = 0;
  /**
   * @var Camera The camera.
   */
  protected Camera $camera {
    get {
      return $this->gameScene->camera;
    }
  }
  /**
   * @var MapLocation The location of the player.
   */
  public MapLocation $location {
    get {
      return $this->gameScene->party->location;
    }
  }
  /**
   * @var bool Whether the player is at a save point.
   */
  public bool $isAtSavePoint = false;
  /**
   * @var bool Whether the player can save the game.
   */
  public bool $canSave {
    get {
      $canSave = false;

      // Are we at a save point?
      if ($this->isAtSavePoint) {
        $canSave = true;
      }

      // Are we in the overworld?
      if ($this->location->name === 'Overworld') {
        $canSave = true;
      }

      return $canSave;
    }
  }

  /**
   * The constructor of the MapManager.
   *
   * @param Game $game The game instance.
   * @param GameScene $gameScene The game scene.
   */
  protected function __construct(
    protected Game $game,
    protected(set) GameScene $gameScene)
  {
  }

  /**
   * Returns the instance of the MapManager.
   *
   * @param Game $game The game instance.
   * @return MapManager The instance of the MapManager.
   */
  public static function getInstance(Game $game, GameScene $gameScene): self
  {
    if (!self::$instance) {
      self::$instance = new self($game, $gameScene);
    }

    return self::$instance;
  }

  /**
   * Loads the map from a file.
   *
   * @param string $filename The filename of the map.
   * @param Player $player The player.
   * @return MapManager The instance of the MapManager.
   * @throws IchilotoException If the map cannot be loaded.
   * @throws NotFoundException If the file is not found.
   */
  public function loadMap(string $filename, Player $player): self
  {
    // Load the tile map from the file
    $this->loadTileMap($filename, $player);
    Console::clear();
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
    return !in_array($collisionType, [CollisionType::SOLID, CollisionType::NPC]);
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
   * @param Player $player The player.
   *
   * @return void
   * @throws IchilotoException If the tile map cannot be loaded.
   * @throws NotFoundException If the file is not found.
   * @throws RequiredFieldException
   */
  private function loadTileMap(string $filename, Player $player): void
  {
    $map = $this->readMapDataFromFile($filename);
    $locationName = $map['name'] ?? MapLocation::DEFAULT_LOCATION_NAME;
    $locationRegion = $map['region'] ?? MapLocation::DEFAULT_LOCATION_REGION;
    $this->gameScene->party->location = new MapLocation($locationName, $locationRegion);

    $this->calculateMapDimensions();
    $this->loadCollisionMap($this->tileMap);
    $this->loadMapTriggers($map['triggers'] ?? []);
    $this->loadMapEvents($map['events'] ?? []);

    $this->camera->resetPosition($player);
  }

  /**
   * Loads the collision map from a tile map.
   *
   * @param string[] $tileMap The tile map.
   * @return void
   * @throws NotFoundException
   */
  private function loadCollisionMap(array $tileMap): void
  {
    $dictionary = $this->getCollisionDictionary();
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
        $cleanedTile = ASCII::to_ascii($tile);
        $collisionRow[] = $dictionary[$cleanedTile]->value ?? CollisionType::SOLID->value;
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
    $this->camera->renderMap();
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
    $this->renderBackgroundTile($x, $y);
  }

  /**
   * Loads the map triggers.
   *
   * @param array<array<string, mixed>> $triggers The list of triggers.
   * @throws IchilotoException If the trigger cannot be created from the array.
   */
  protected function loadMapTriggers(array $triggers): void
  {
    if ($player = $this->gameScene->player) {
      $player->removeTriggers();

      foreach ($triggers as $data) {
        $trigger = MapTrigger::tryFromArray($data);
        $player->addTrigger($trigger);
      }
    }
  }

  /**
   * Loads the map events.
   *
   * @param array<array<string, mixed>> $events The list of events.
   * @return void
   * @throws NotFoundException If the class does not exist.
   * @throws RequiredFieldException If a required field is missing.
   */
  protected function loadMapEvents(array $events): void
  {
    if ($player = $this->gameScene->player) {
      $player->removeEventTriggers();

      foreach ($events as $eventData) {
        $eventTrigger = EventTriggerFactory::create($eventData);
        $player->addTrigger($eventTrigger);
      }
    }
  }

  /**
   * Renders a background tile.
   *
   * @param int $x The x-coordinate of the tile.
   * @param int $y The y-coordinate of the tile.
   * @return void
   */
  public function renderBackgroundTile(int $x, int $y): void
  {
    $tile = $this->tileMap[$y][$x];
    $screenSpacePosition = $this->camera->getScreenSpacePosition(new Vector2($x, $y));
    $this->camera->draw($tile, $screenSpacePosition->x, $screenSpacePosition->y);
  }

  /**
   * Gets the collision dictionary from a file.
   *
   * @return CollisionType[] The collision dictionary.
   * @throws NotFoundException If the file is not found.
   */
  protected function getCollisionDictionary(): array
  {
    $collisionDictionaryFilename = Path::join(Path::getCurrentWorkingDirectory(), 'assets/Maps/collisions.php');
    return $this->loadCollisionDictionary($collisionDictionaryFilename);
  }

  /**
   * Scrolls the map.
   *
   * @param Player $player The player.
   * @param Vector2 $moveDirection The direction to move.
   * @return bool True if the map was scrolled, false otherwise.
   */
  public function scrollMap(Player $player, Vector2 $moveDirection): bool
  {
    $didScroll = false;
    $halfScreenWidth = $this->camera->screen->getWidth() / 2;
    $halfScreenHeight = $this->camera->screen->getHeight() / 2;

    if ($this->mapIsSmallerThanScreen($this->camera->screen)) {
      return false;
    }

    switch ($moveDirection) {
      case Vector2::left():
        $playerDistanceFromLeftScreenEdge = $player->position->x - $this->camera->screen->getLeft();
        if ($playerDistanceFromLeftScreenEdge < $halfScreenWidth) {
          if (($player->position->x - $halfScreenWidth) > 0) {
            $newX = max(0, $this->camera->position->x - 1);
            $newX = clamp($newX, 0, $this->camera->screen->getWidth());
            $this->camera->screen->setX(max(0, $newX));
            $didScroll = true;
          }
        }
        break;

      case Vector2::right():
        $playerDistanceFromRightScreenEdge = $this->camera->screen->getRight() - $player->position->x;
        if ($playerDistanceFromRightScreenEdge < $halfScreenWidth) {
          if (($player->position->x + $halfScreenWidth) < $this->mapWidth) {
            $newX = min($this->mapWidth - $halfScreenWidth,$this->camera->position->x + 1);
            $newX = clamp($newX, 0, $this->camera->screen->getWidth());
            $this->camera->screen->setX(min($this->mapWidth - $halfScreenWidth, $newX));
            $didScroll = true;
          }
        }
        break;

      case Vector2::up():
        $playerDistanceFromBottomScreenEdge = $player->position->y - $this->camera->screen->getTop();
        if ($playerDistanceFromBottomScreenEdge < $halfScreenHeight) {
          $newY = max(0, $this->camera->position->y - 1);
          $newY = clamp($newY, 0, $this->camera->screen->getHeight());
          $this->camera->screen->setY($newY);
          $didScroll = true;
        }
        break;

      case Vector2::down():
        $playerDistanceFromBottomScreenEdge = $this->camera->screen->getBottom() - $player->position->y;
        if ($playerDistanceFromBottomScreenEdge < $halfScreenHeight) {
          if (($player->position->y + $halfScreenHeight) < $this->mapHeight) {
            $newY = min($this->mapHeight - $halfScreenHeight, $this->camera->position->y + 1);
            $newY = clamp($newY, 0, $this->camera->screen->getHeight());
            $this->camera->screen->setY($newY);
            $didScroll = true;
          }
        }
        break;
    }

    return $didScroll;
  }

  /**
   * Calculates the dimensions of the map.
   *
   * @return void
   */
  protected function calculateMapDimensions(): void
  {
    $this->mapHeight = count($this->tileMap);
    $this->mapWidth = array_reduce($this->tileMap, fn($carry, $row) => max($carry, strlen($row)), 0);
  }

  /**
   * Determines if the map is smaller than the screen.
   *
   * @param Rect $screen The screen.
   * @return bool True if the map is smaller than the screen, false otherwise.
   */
  protected function mapIsSmallerThanScreen(Rect $screen): bool
  {
    return $this->screenIsWiderThanMap($screen) && $this->screenIsTallerThanMap($screen);
  }

  /**
   * Determines if the map is thinner than the screen.
   *
   * @param Rect $screen The screen.
   * @return bool
   */
  public function screenIsWiderThanMap(Rect $screen): bool
  {
    return $this->mapWidth <= $screen->getWidth();
  }

  /**
   * Determines if the map is shorter than the screen.
   *
   * @param Rect $screen The screen.
   * @return bool True if the map is shorter than the screen, false otherwise.
   */
  protected function screenIsTallerThanMap(Rect $screen): bool
  {
    return $this->mapHeight <= $screen->getHeight();
  }

  /**
   * @param string $filename
   * @return mixed
   * @throws NotFoundException
   */
  public function readMapDataFromFile(string $filename): mixed
  {
    $assetsDirectory = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    if (!str_ends_with($filename, '.php')) {
      $filename .= '.php';
    }
    $filename = Path::join($assetsDirectory, 'Maps', $filename);

    if (!file_exists($filename)) {
      throw new NotFoundException("File $filename not found.");
    }

    $map = require $filename;

    if (false === $map) {
      throw new NotFoundException("File $filename does not return an array.");
    }

    $this->tileMap = $map['tile_map'] ?? throw new InvalidArgumentException("tile_map not found in map array of $filename.");
    $this->camera->worldSpace = $this->tileMap;
    return $map;
  }
}