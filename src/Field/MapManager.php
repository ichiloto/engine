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
use Ichiloto\Engine\IO\Console\TerminalText;
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
   * @var array<int, string[]> The tile map.
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
   * @param array<int, string[]> $tileMap The tile map.
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
   * @param array<int, string[]|string> $tilemap The tile map.
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

      $tiles = is_array($row) ? $row : TerminalText::visibleSymbols($row);

      foreach ($tiles as $tile) {
        $cleanedTile = ASCII::to_ascii(TerminalText::stripAnsi($tile));
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
    $tile = $this->tileMap[$y][$x] ?? ' ';
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
    $horizontalFocus = $this->camera->getHorizontalFocusPosition();
    $verticalFocus = $this->camera->getVerticalFocusPosition();
    $rightViewportPadding = $this->camera->screen->getWidth() - $horizontalFocus - 1;
    $bottomViewportPadding = $this->camera->screen->getHeight() - $verticalFocus - 1;
    $canScrollHorizontally = ! $this->screenIsWiderThanMap($this->camera->screen);
    $canScrollVertically = ! $this->screenIsTallerThanMap($this->camera->screen);
    $maxX = max(0, $this->mapWidth - $this->camera->screen->getWidth());
    $maxY = max(0, $this->mapHeight - $this->camera->screen->getHeight());

    if (! $canScrollHorizontally && ! $canScrollVertically) {
      return false;
    }

    switch ($moveDirection) {
      case Vector2::left():
        if (! $canScrollHorizontally) {
          break;
        }
        $playerDistanceFromLeftScreenEdge = $player->position->x - $this->camera->screen->getLeft();
        if ($playerDistanceFromLeftScreenEdge < $horizontalFocus) {
          if (($player->position->x - $horizontalFocus) > 0) {
            $newX = max(0, $this->camera->position->x - 1);
            $this->camera->screen->setX(clamp($newX, 0, $maxX));
            $didScroll = true;
          }
        }
        break;

      case Vector2::right():
        if (! $canScrollHorizontally) {
          break;
        }
        $playerDistanceFromRightScreenEdge = ($this->camera->screen->getRight() - 1) - $player->position->x;
        if ($playerDistanceFromRightScreenEdge < $rightViewportPadding) {
          if (($player->position->x + $rightViewportPadding) < $this->mapWidth - 1) {
            $newX = min($maxX, $this->camera->position->x + 1);
            $this->camera->screen->setX(clamp($newX, 0, $maxX));
            $didScroll = true;
          }
        }
        break;

      case Vector2::up():
        if (! $canScrollVertically) {
          break;
        }
        $playerDistanceFromTopScreenEdge = $player->position->y - $this->camera->screen->getTop();
        if ($playerDistanceFromTopScreenEdge < $verticalFocus) {
          $newY = max(0, $this->camera->position->y - 1);
          $this->camera->screen->setY(clamp($newY, 0, $maxY));
          $didScroll = true;
        }
        break;

      case Vector2::down():
        if (! $canScrollVertically) {
          break;
        }
        $playerDistanceFromBottomScreenEdge = ($this->camera->screen->getBottom() - 1) - $player->position->y;
        if ($playerDistanceFromBottomScreenEdge < $bottomViewportPadding) {
          if (($player->position->y + $bottomViewportPadding) < $this->mapHeight - 1) {
            $newY = min($maxY, $this->camera->position->y + 1);
            $this->camera->screen->setY(clamp($newY, 0, $maxY));
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
    $this->mapWidth = array_reduce($this->tileMap, fn($carry, $row) => max($carry, count($row)), 0);
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
    return $this->readSplitMapDataFromFiles($this->resolveMapPaths($filename));
  }

  /**
   * Resolves the canonical file paths for the supplied map ID.
   *
   * @param string $filename The logical map filename or any of its PHP file variants.
   * @return array{id: string, data: string, map: string, event: string} The resolved file paths.
   */
  protected function resolveMapPaths(string $filename): array
  {
    $assetsDirectory = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Maps');
    $mapId = preg_replace('/(\.(data|map|event))?\.php$/', '', $filename) ?: $filename;
    $mapLeafName = basename(str_replace('\\', '/', $mapId));
    $directory = Path::join($assetsDirectory, $mapId);

    return [
      'id' => $mapId,
      'data' => Path::join($directory, "{$mapLeafName}.data.php"),
      'map' => Path::join($directory, "{$mapLeafName}.map.php"),
      'event' => Path::join($directory, "{$mapLeafName}.event.php"),
    ];
  }

  /**
   * Reads a split map definition from `.data.php`, `.map.php`, and `.event.php` files.
   *
   * @param array{id: string, data: string, map: string, event: string} $paths The resolved map file paths.
   * @return array<string, mixed> The hydrated map data.
   * @throws NotFoundException If any required split-map file is missing.
   */
  protected function readSplitMapDataFromFiles(array $paths): array
  {
    foreach (['data', 'map', 'event'] as $type) {
      if (! file_exists($paths[$type])) {
        throw new NotFoundException("File {$paths[$type]} not found.");
      }
    }

    $map = require $paths['data'];

    if (! is_array($map)) {
      throw new NotFoundException("File {$paths['data']} does not return an array.");
    }

    $this->tileMap = $this->parseMapLayer(require $paths['map'], $paths['map'], 'map');
    $this->camera->worldSpace = $this->tileMap;

    $eventLayer = $this->parseMapLayer(require $paths['event'], $paths['event'], 'event');
    $this->assertEventLayerMatchesTileMap($eventLayer, $paths['event']);
    $map['events'] = $this->resolveEventDefinitions($map['events'] ?? [], $eventLayer, $paths['event']);

    return $map;
  }

  /**
   * Parses a text-based map layer into symbol rows.
   *
   * @param string|string[] $layer The raw layer content.
   * @param string $filename The source filename.
   * @param string $fieldName The layer label used in validation errors.
   * @return array<int, string[]> The parsed symbol grid.
   */
  protected function parseMapLayer(string|array $layer, string $filename, string $fieldName): array
  {
    $rows = match (true) {
      is_string($layer) => preg_split('/\r\n|\n|\r/', rtrim($layer, "\r\n")) ?: [],
      default => $layer,
    };

    if ($rows === []) {
      return [];
    }

    foreach ($rows as $rowIndex => $row) {
      if (! is_string($row)) {
        throw new InvalidArgumentException("{$fieldName} row {$rowIndex} in {$filename} must be a string.");
      }
    }

    return array_map(
      static fn(string $row): array => TerminalText::visibleSymbols($row),
      $rows
    );
  }

  /**
   * Ensures the event overlay matches the tile-map dimensions exactly.
   *
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return void
   */
  protected function assertEventLayerMatchesTileMap(array $eventLayer, string $filename): void
  {
    if (count($eventLayer) !== count($this->tileMap)) {
      throw new InvalidArgumentException("Event map {$filename} must have " . count($this->tileMap) . " rows.");
    }

    foreach ($this->tileMap as $rowIndex => $tileRow) {
      $eventRow = $eventLayer[$rowIndex] ?? [];

      if (count($eventRow) !== count($tileRow)) {
        throw new InvalidArgumentException("Event map {$filename} row {$rowIndex} must be " . count($tileRow) . " tiles wide.");
      }
    }
  }

  /**
   * Resolves event definitions against the event overlay.
   *
   * @param array<int|string, array<string, mixed>> $events The map event definitions.
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return array<int, array<string, mixed>> The resolved runtime event data.
   */
  protected function resolveEventDefinitions(array $events, array $eventLayer, string $filename): array
  {
    $areas = $this->extractEventAreas($eventLayer, $filename);

    if ($events === []) {
      if ($areas !== []) {
        throw new InvalidArgumentException("Event markers were found in {$filename}, but no event definitions exist in the map data.");
      }

      return [];
    }

    $resolvedEvents = [];

    foreach ($events as $marker => $eventDefinition) {
      if (! is_array($eventDefinition)) {
        throw new InvalidArgumentException("Invalid event definition found in {$filename}.");
      }

      if (isset($eventDefinition['area']) && ! is_string($marker)) {
        $resolvedEvents[] = $eventDefinition;
        continue;
      }

      $resolvedMarker = is_string($marker) ? $marker : ($eventDefinition['marker'] ?? null);

      if (! is_string($resolvedMarker) || TerminalText::displayWidth($resolvedMarker) !== 1) {
        throw new InvalidArgumentException("Events in split map data must be keyed by a single-character marker or declare one explicitly.");
      }

      $area = $areas[$resolvedMarker] ?? throw new InvalidArgumentException("Event marker '{$resolvedMarker}' was not found in {$filename}.");
      unset($areas[$resolvedMarker], $eventDefinition['marker']);
      $eventDefinition['area'] = $area;
      $resolvedEvents[] = $eventDefinition;
    }

    if ($areas !== []) {
      $unusedMarkers = implode(', ', array_keys($areas));
      throw new InvalidArgumentException("Unmapped event markers found in {$filename}: {$unusedMarkers}.");
    }

    return $resolvedEvents;
  }

  /**
   * Extracts rectangular event areas from the event overlay.
   *
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return array<string, array{x: int, y: int, width: int, height: int}> The resolved areas keyed by marker.
   */
  protected function extractEventAreas(array $eventLayer, string $filename): array
  {
    $bounds = [];

    foreach ($eventLayer as $y => $row) {
      foreach ($row as $x => $tile) {
        $marker = TerminalText::stripAnsi($tile);

        if (trim($marker) === '') {
          continue;
        }

        if (! isset($bounds[$marker])) {
          $bounds[$marker] = [
            'minX' => $x,
            'maxX' => $x,
            'minY' => $y,
            'maxY' => $y,
          ];
          continue;
        }

        $bounds[$marker]['minX'] = min($bounds[$marker]['minX'], $x);
        $bounds[$marker]['maxX'] = max($bounds[$marker]['maxX'], $x);
        $bounds[$marker]['minY'] = min($bounds[$marker]['minY'], $y);
        $bounds[$marker]['maxY'] = max($bounds[$marker]['maxY'], $y);
      }
    }

    $areas = [];

    foreach ($bounds as $marker => $markerBounds) {
      for ($y = $markerBounds['minY']; $y <= $markerBounds['maxY']; $y++) {
        for ($x = $markerBounds['minX']; $x <= $markerBounds['maxX']; $x++) {
          $cell = TerminalText::stripAnsi($eventLayer[$y][$x] ?? ' ');

          if ($cell !== $marker) {
            throw new InvalidArgumentException("Event marker '{$marker}' in {$filename} must occupy a solid rectangle.");
          }
        }
      }

      $areas[$marker] = [
        'x' => $markerBounds['minX'],
        'y' => $markerBounds['minY'],
        'width' => $markerBounds['maxX'] - $markerBounds['minX'] + 1,
        'height' => $markerBounds['maxY'] - $markerBounds['minY'] + 1,
      ];
    }

    return $areas;
  }
}
