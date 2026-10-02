<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\Console;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRenderAt;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\PartyLocation as MapLocation;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
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
  protected(set) array $tileMap = [];
  public private(set) ?MapLayerSet $layers = null;
  public private(set) ?MapGraphics $graphics = null;
  private ?PresentationWorld $presentationWorld = null;
  private ?bool $presentationWorldPolicy = null;
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
  protected(set) int $mapWidth = 0;
  /**
   * @var int The height of the map.
   */
  protected(set) int $mapHeight = 0;
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
   * The background music the current map declares through the `bgm` entry in
   * its data file, or null when the map declares none.
   *
   * @var string|null
   */
  protected(set) ?string $backgroundMusic = null;
  private mixed $declaredBackgroundMusic = null;
  private mixed $backgroundMusicVariants = [];
  private bool $hasMusicDeclaration = false;
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
    // Loading owns map state only. The active scene composes the complete
    // field after the player, NPCs, cues, UI, and presentation layers are
    // ready; drawing a partial map here caused duplicate clears and exposed
    // intermediate frames during transfers.
    $this->loadTileMap($filename, $player);
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

    // Live NPCs block a tile the same way authored NPC tiles do.
    if ($this->gameScene->npcManager?->npcAt($x, $y) !== null) {
      $collisionType = CollisionType::NPC;
      return false;
    }

    if ($this->gameScene->cinematicStage?->actorAt($x, $y) !== null) {
      $collisionType = CollisionType::NPC;
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
   * PHP normalizes numeric-string array keys such as `"8"` to integers, so
   * single decimal digit keys are valid tile glyphs alongside string keys.
   *
   * @return array<int|string, CollisionType|array<int|string, CollisionType>> The collision dictionary.
   * @throws NotFoundException
   */
  public function loadCollisionDictionary(string $filename): array
  {
    return MapSourceReader::loadCollisionDictionary($filename);
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
    $this->applyPreparedMap($this->prepareMap($filename), $player);
  }

  /** Validates every map source and trigger before changing the active field. */
  public function prepareMap(string $filename): PreparedMap
  {
    $source = $this->prepareSplitMapDataFromFiles($this->resolveMapPaths($filename));
    $map = $source['data'];
    $collisions = $this->generateLayerCollisionMap($source['layers'], $this->getCollisionDictionary());
    $mapTriggers = [];
    foreach ($map['triggers'] ?? [] as $trigger) {
      $mapTriggers[] = MapTrigger::tryFromArray($trigger);
    }
    $eventTriggers = [];
    $mapId = strval($map['id'] ?? '');
    foreach ($map['events'] ?? [] as $event) {
      $eventTriggers[] = EventTriggerFactory::create($event, $mapId !== '' ? $mapId : null);
    }
    $npcs = $this->gameScene->npcManager?->prepareNpcs(
      is_array($map['npcs'] ?? null) ? $map['npcs'] : [],
      $mapId,
    );

    return new PreparedMap($map, $source['tiles'], $collisions, $mapTriggers, $eventTriggers, $npcs, $source['layers'],
      $source['graphics']);
  }

  /** Commits a previously validated destination and its field side effects. */
  public function applyPreparedMap(PreparedMap $prepared, Player $player): void
  {
    $map = $prepared->data;
    $this->tileMap = $prepared->tiles;
    $this->layers = $prepared->layers;
    $this->graphics = $prepared->graphics;
    $this->clearPresentationWorld();
    $this->collisionMap = $prepared->collisions;
    $this->camera->worldSpace = $prepared->tiles;
    $locationName = $map['name'] ?? MapLocation::DEFAULT_LOCATION_NAME;
    $locationRegion = $map['region'] ?? MapLocation::DEFAULT_LOCATION_REGION;
    $this->gameScene->party->location = new MapLocation($locationName, $locationRegion);

    $this->calculateMapDimensions();
    $mapId = strval($map['id'] ?? '');
    $this->loadMapTriggers($prepared->mapTriggers);
    $this->loadMapEvents($prepared->eventTriggers, $mapId);
    $this->applyMapBackgroundMusic($map['bgm'] ?? null, $map['bgmVariants'] ?? []);

    if ($mapId !== '') {
      $this->gameScene->questManager?->recordMapEntered($mapId);
      $this->gameScene->gameState?->markMapVisited($mapId);
    }

    $this->gameScene->encounterManager?->configure(
      is_array($map['encounters'] ?? null) ? $map['encounters'] : null
    );
    $this->gameScene->npcManager?->applyPreparedNpcs($prepared->npcs ?? []);
    $this->gameScene->skitManager?->announceAvailableSkits();

    $this->camera->resetPosition($player);
    $this->gameScene->fieldEffects?->installMap($mapId, $map['fieldEffects'] ?? null, $prepared->graphics,
      $prepared->eventTriggers);
  }

  /**
   * Applies the map's declared background music.
   *
   * A map declares its default `bgm` as a string. It may additionally declare
   * ordered `bgmVariants` for story-dependent map states:
   *
   * ```php
   * 'bgm' => 'town-in-danger',
   * 'bgmVariants' => [[
   *   'track' => 'quiet-town',
   *   'conditions' => [['type' => 'event', 'name' => 'crisis_resolved']],
   * ]],
   * ```
   *
   * The first matching variant wins. A map that resolves to no track keeps
   * whatever music is already playing, mirroring RPG Maker's autoplay
   * semantics.
   *
   * @param mixed $bgm The `bgm` entry from the map data file.
   * @param mixed $variants The optional `bgmVariants` entries.
   * @return void
   */
  protected function applyMapBackgroundMusic(mixed $bgm, mixed $variants = []): void
  {
    $this->declaredBackgroundMusic = $bgm;
    $this->backgroundMusicVariants = $variants;
    $this->hasMusicDeclaration = true;
    $this->resolveCurrentBackgroundMusic();
    $this->gameScene->refreshFieldMusic(force: $this->backgroundMusic !== null, keepSilence: true);
  }

  /** Re-evaluate map variants on state changes and battle returns, not just entry. */
  public function resolveCurrentBackgroundMusic(): ?string
  {
    if ($this->hasMusicDeclaration) {
      $this->backgroundMusic = $this->resolveMapBackgroundMusic(
        $this->declaredBackgroundMusic, $this->backgroundMusicVariants,
      );
    }
    return $this->backgroundMusic;
  }

  /**
   * Resolves a map's static or world-state-dependent background music.
   */
  protected function resolveMapBackgroundMusic(mixed $bgm, mixed $variants = []): ?string
  {
    if (is_array($variants)) {
      foreach ($variants as $variant) {
        if (! is_array($variant)) {
          continue;
        }

        $track = is_string($variant['track'] ?? null) ? trim($variant['track']) : '';
        $conditions = $variant['conditions'] ?? [];
        if ($track === '' || ! is_array($conditions)) {
          continue;
        }

        if (WorldConditionEvaluator::allHold(
          $conditions,
          $this->gameScene->gameState,
          $this->gameScene->party,
        )) {
          return $track;
        }
      }
    }

    $default = is_string($bgm) ? trim($bgm) : '';

    return $default !== '' ? $default : null;
  }

  /**
   * Generates a collision map from a tile map.
   *
   * @param array<int, string[]|string> $tilemap The tile map.
   * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary The dictionary that maps tile glyphs to collision types.
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
        $type = $dictionary[$cleanedTile] ?? CollisionType::SOLID;
        $collisionRow[] = $type instanceof CollisionType && $type !== CollisionType::PASS_THROUGH
          ? $type->value : CollisionType::SOLID->value;
      }

      $collisionMap[] = $collisionRow;
    }

    return $collisionMap;
  }

  /**
   * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
   * @return int[][]
   */
  public function generateLayerCollisionMap(MapLayerSet $layers, array $dictionary = []): array
  {
    return MapCollisionResolver::resolveLayers($layers, $dictionary ?: $this->defaultCollisionDictionary);
  }

  /**
   * Renders the map.
   *
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    if (Console::isRetainedWorldPresentation()) { $this->getPresentationWorld(); }
    $this->camera->renderMap();
  }

  /** Build once per installed map/policy, only when a graphical consumer requests it. */
  public function getPresentationWorld(): ?PresentationWorld
  {
    if ($this->layers === null) {
      $this->camera->setRetainedWorldAvailable(false);
      return null;
    }
    $policy = TerminalCapabilities::supportsCompositeEmoji();
    if ($this->presentationWorldPolicy === $policy) {
      $this->camera->setRetainedWorldAvailable($this->presentationWorld !== null);
      return $this->presentationWorld;
    }
    $this->presentationWorldPolicy = $policy;
    $this->presentationWorld = null;
    try {
      $this->presentationWorld = PresentationWorld::getFromLayers($this->layers, 'map', $this->graphics, $this->getAssetRoot());
    } catch (\Throwable $error) {
      // Unsupported world bounds keep the screen-space retained text path usable.
      Debug::warn('Retained map presentation is unavailable: ' . $error->getMessage());
    }
    $this->camera->setRetainedWorldAvailable($this->presentationWorld !== null);
    return $this->presentationWorld;
  }

  private function clearPresentationWorld(): void
  {
    $this->presentationWorld = null;
    $this->presentationWorldPolicy = null;
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
        $trigger = $data instanceof MapTrigger ? $data : MapTrigger::tryFromArray($data);
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
  protected function loadMapEvents(array $events, string $mapId = ''): void
  {
    if ($player = $this->gameScene->player) {
      $player->removeEventTriggers();
      $gameState = $this->gameScene->gameState;

      foreach ($events as $eventData) {
        $eventTrigger = $eventData instanceof \Ichiloto\Engine\Events\Triggers\EventTrigger
          ? $eventData : EventTriggerFactory::create($eventData, $mapId !== '' ? $mapId : null);
        $eventTrigger->bind($gameState, $this->gameScene->party);

        // A one-shot event the world state already records as completed
        // stays completed — a looted chest does not refill on map re-entry.
        if (
          ! $eventTrigger->isReusable &&
          $eventTrigger->mapId !== null &&
          $eventTrigger->marker !== null &&
          $gameState->isEventComplete($eventTrigger->mapId, $eventTrigger->marker)
        ) {
          $eventTrigger->restoreCompleted();
        }

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
    if (Console::isRetainedWorldPresentation()) { $this->getPresentationWorld(); }
    $this->camera->renderBackgroundTile($x, $y);
  }

  /**
   * Gets the collision dictionary from a file.
   *
   * @return array<int|string, CollisionType|array<int|string, CollisionType>> The collision dictionary.
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
    if (! $this->camera->followsPlayer) {
      return false;
    }

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
    return MapSourceReader::resolvePaths(Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Maps'), $filename);
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
    $prepared = $this->prepareSplitMapDataFromFiles($paths);
    $this->gameScene->fieldEffects?->clear();
    $this->tileMap = $prepared['tiles'];
    $this->layers = $prepared['layers'];
    $this->graphics = $prepared['graphics'];
    $this->clearPresentationWorld();
    $this->camera->worldSpace = $prepared['tiles'];

    return $prepared['data'];
  }

  /** Clears loaded geometry and presentation together for map preview lifecycles. */
  protected function clearMapGeometry(): void
  {
    $this->gameScene->fieldEffects?->clear();
    $this->tileMap = [];
    $this->collisionMap = [];
    $this->layers = null;
    $this->graphics = null;
    $this->clearPresentationWorld();
    $this->calculateMapDimensions();
    $this->camera->worldSpace = [];
  }

  /**
   * @param array{id: string, data: string, map: string, event: string} $paths
   * @return array{data: array<string, mixed>, tiles: array<int, string[]>, layers: MapLayerSet, graphics: ?MapGraphics}
   */
  protected function prepareSplitMapDataFromFiles(array $paths): array
  {
    $source = MapSourceReader::readFiles($paths);
    $map = $source['data'];

    if (array_key_exists('tiles2d', $map)) {
      // Glyph-keyed crops are retired; a map draws graphics from its tileset.
      Debug::warn("{$paths['id']}/" . basename($paths['data']) . " tiles2d is no longer read; its map shows terminal glyphs until it has a tileset.");
    }

    $graphics = null;
    try {
      $graphics = MapGraphics::loadFromDirectory(dirname($paths['data']), $paths['id'], $map['tileset'] ?? null,
        $source['layers'], $this->getAssetRoot(), $map[MapGraphics::SETTINGS_KEY] ?? null);
    } catch (\Throwable $error) {
      // Graphics never decide whether a map loads: it shows its terminal glyphs instead.
      Debug::warn("Map {$paths['id']} graphics are unusable; showing terminal glyphs: " . $error->getMessage());
    }

    return [...$source, 'graphics' => $graphics];
  }

  /** The project's asset root, where tilesets and their sheets live. */
  private function getAssetRoot(): string
  {
    return Path::join(Path::getCurrentWorkingDirectory(), 'assets');
  }

  /**
   * Ensures the event overlay matches the tile-map dimensions exactly.
   *
   * @param array<int, string[]> $eventLayer The parsed event overlay.
   * @param string $filename The event-layer filename.
   * @return void
   */
  protected function assertEventLayerMatchesTileMap(array $eventLayer, string $filename, ?array $tileMap = null): void
  {
    $tileMap ??= $this->tileMap;
    if (count($eventLayer) !== count($tileMap)) {
      throw new InvalidArgumentException("Event map {$filename} must have " . count($tileMap) . " rows.");
    }

    foreach ($tileMap as $rowIndex => $tileRow) {
      $eventRow = $eventLayer[$rowIndex] ?? [];

      if (count($eventRow) !== count($tileRow)) {
        throw new InvalidArgumentException("Event map {$filename} row {$rowIndex} must be " . count($tileRow) . " tiles wide.");
      }
    }
  }
}
