<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/**
 * A project tileset: RPG Maker sheets by name, the graphical flags RPG Maker
 * keeps per tile, the pieces maps are built from with it and the terrain
 * kinds world maps are painted in ({@see TerrainKind}), the tile layers
 * whose tiles stack so decorations drawn on them overlap ({@see TilesetStack}),
 * the piece a world map shows for each type of place
 * ({@see \Ichiloto\Engine\Field\PlaceTypeCatalog}), and the thickets
 * characters walk through hidden to the waist or the ankles ({@see TilesetThicket}).
 * Tiles carry no
 * passage settings: collision comes from the map's occupancy or terminal
 * layers, and on a map painted in terrain kinds from its kinds
 * ({@see \Ichiloto\Engine\Field\MapTerrainKinds}). What each tile shows at
 * ground level ({@see TilesetAppearance}) is for validation alone and never
 * changes passage; a kind's own autotile shows what its passage says.
 * Loaded from `Data/Tilesets/<id>.php`.
 */
final readonly class Tileset
{
  public const string DIRECTORY = 'Data/Tilesets';
  /** The key naming the piece shown for each type of place. */
  public const string PLACE_ICONS = 'placeIcons';
  private const array FIELDS = ['name', 'sheets', 'above', 'tables', 'pieces', 'kinds', 'stacks', 'missingArt', 'shadows',
    TilesetAppearance::DATA_KEY, self::PLACE_ICONS, TilesetThicket::DATA_KEY];

  /**
   * @param array<string, string> $sheets Asset-relative PNG paths keyed by sheet name.
   * @param list<int> $above Tile identities drawn above characters (every shape of an autotile kind).
   * @param list<int> $tables A2 autotile identities drawn as tables.
   * @param array<string, TilesetPiece> $pieces Whole items, keyed by piece id, in authored order.
   * @param int|null $missingArt The plain tile that marks a cell whose art is still to do, or null.
   * @param TilesetShadows|null $shadows The shadow its raised tiles cast, or null when none do.
   * @param TilesetAppearance $appearance What its tiles show at ground level, read by validation only.
   * @param array<string, TerrainKind> $kinds Terrain kinds, keyed by kind id, in authored order.
   * @param array<string, TilesetStack> $stacks Tile layers whose tiles stack, keyed by stack id.
   * @param array<string, string> $placeIcons The piece id a map drawn with this tileset shows for each place type
   *     id, so the icon over an entrance follows the type of the place it leads to.
   * @param array<string, TilesetThicket> $thickets Thickets, keyed by thicket id, in authored order.
   */
  public function __construct(
    public string $id,
    public string $name,
    public array $sheets,
    public array $above = [],
    public array $tables = [],
    public array $pieces = [],
    public ?int $missingArt = null,
    public ?TilesetShadows $shadows = null,
    public TilesetAppearance $appearance = new TilesetAppearance(),
    public array $kinds = [],
    public array $stacks = [],
    public array $placeIcons = [],
    public array $thickets = [],
  ) {
    $byTile = [];
    foreach ($thickets as $thicket) {
      foreach ($thicket->tiles as $tile) {
        $byTile[$tile] = $thicket;
      }
    }
    foreach ($kinds as $kind) {
      if ($kind->thicket !== null && $kind->art !== null) {
        $byTile[$kind->art['tile']] = $thickets[$kind->thicket];
      }
    }
    $this->thicketsByTile = $byTile;
  }

  /** @var array<int, TilesetThicket> The thicket each tile is, by flag identity. */
  private array $thicketsByTile;

  /**
   * The thicket a tile is, in any of its shapes: the one its terrain kind
   * names for a kind's own autotile, otherwise the one that lists it; null
   * for a tile that hides nobody.
   */
  public function getThicket(int $id): ?TilesetThicket
  {
    return $id === TileId::EMPTY ? null : $this->thicketsByTile[TileId::getFlagId($id)] ?? null;
  }

  /** The piece a map drawn with this tileset shows for a place type, or null when it gives the type none. */
  public function getPlaceIcon(string $placeType): ?TilesetPiece
  {
    return isset($this->placeIcons[$placeType]) ? $this->pieces[$this->placeIcons[$placeType]] : null;
  }

  /** The stack a piece's tiles are laid on, or null when it is stamped as authored. */
  public function getStackOf(TilesetPiece $piece): ?TilesetStack
  {
    foreach ($this->stacks as $stack) {
      if ($stack->isStackedPiece($piece)) {
        return $stack;
      }
    }
    return null;
  }

  /**
   * The tile layers a piece's tiles go on: those it names, or every layer of
   * the stack it is laid on.
   *
   * @return list<string>
   */
  public function getPieceTileLayers(TilesetPiece $piece): array
  {
    return $this->getStackOf($piece)?->layers
      ?? array_map(strval(...), array_keys($piece->connects === null ? $piece->tiles : $piece->shapeTiles));
  }

  /**
   * Everything an editor places on a map, by id: the authored pieces, then,
   * on a map painted in terrain kinds, each kind's piece. A map that has not
   * opted into kinds never binds their glyphs and tiles.
   *
   * @return array<string, TilesetPiece>
   */
  public function getPlacements(bool $includeKinds): array
  {
    $placements = $this->pieces;
    foreach ($includeKinds ? $this->kinds : [] as $id => $kind) {
      $placements[$id] = $kind->piece;
    }
    return $placements;
  }

  public static function load(string $assetRoot, string $id): self
  {
    if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
      throw new InvalidArgumentException("Tileset id '{$id}' must be lowercase letters, digits, hyphens or underscores.");
    }
    $path = $assetRoot . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . $id . '.php';
    if (!is_file($path)) {
      throw new InvalidArgumentException("Tileset {$id} was not found at " . self::DIRECTORY . "/{$id}.php.");
    }
    return self::fromArray($id, (static fn(string $file): mixed => require $file)($path));
  }

  public static function fromArray(string $id, mixed $data): self
  {
    $context = "Tileset {$id}";
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException("{$context} must return an array of " . implode(', ', self::FIELDS) . '.');
    }
    if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
      throw new InvalidArgumentException("{$context} needs a name.");
    }
    $sheets = $data['sheets'] ?? null;
    if (!is_array($sheets) || $sheets === []) {
      throw new InvalidArgumentException("{$context} needs at least one sheet.");
    }
    foreach ($sheets as $sheet => $asset) {
      if (TilesetSheet::tryFrom((string)$sheet) === null) {
        throw new InvalidArgumentException("{$context}: '{$sheet}' is not an RPG Maker sheet (A1 to A5, B to E).");
      }
      if (!is_string($asset) || strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'png') {
        throw new InvalidArgumentException("{$context}: sheet {$sheet} must be an asset-relative PNG path.");
      }
      SpriteValidation::validateAssetPath($asset);
    }
    $above = self::getIdentityList($data['above'] ?? [], "{$context} above");
    $tables = self::getIdentityList($data['tables'] ?? [], "{$context} tables");
    foreach ($tables as $table) {
      if (TileId::getSheet($table) !== TilesetSheet::A2) {
        throw new InvalidArgumentException("{$context}: only A2 autotiles can be tables; {$table} is not one.");
      }
    }
    $pieces = $data['pieces'] ?? [];
    if (!is_array($pieces) || ($pieces !== [] && array_is_list($pieces))) {
      throw new InvalidArgumentException("{$context} pieces must be keyed by piece id.");
    }
    foreach ($pieces as $pieceId => $piece) {
      $pieces[$pieceId] = TilesetPiece::fromArray((string)$pieceId, $piece, $context);
    }
    // The placeholder is drawn whole in its cell, so it is a plain tile
    // from one of the tileset's own sheets.
    $missingArt = $data['missingArt'] ?? null;
    if ($missingArt !== null && (!is_int($missingArt) || $missingArt === TileId::EMPTY || !TileId::isValid($missingArt)
      || TileId::isAutotile($missingArt) || !isset($sheets[TileId::getSheet($missingArt)?->value ?? '']))) {
      throw new InvalidArgumentException("{$context}: missingArt must be a plain tile from one of its sheets.");
    }
    $shadows = array_key_exists('shadows', $data)
      ? TilesetShadows::fromArray($data['shadows'], $context, self::getIdentityList(...)) : null;
    $thickets = TilesetThicket::readAll($data[TilesetThicket::DATA_KEY] ?? [], $context, self::getIdentityList(...));
    $kinds = TerrainKind::readAll($data['kinds'] ?? [], $sheets, array_map(strval(...), array_keys($pieces)), $context,
      array_map(strval(...), array_keys($thickets)));
    self::assertThicketTilesAreNotKinds($thickets, $kinds, $context);
    $appearance = TilesetAppearance::fromArray($data[TilesetAppearance::DATA_KEY] ?? [], $context, self::getIdentityList(...))
      ->withKinds($kinds, $context);
    $kindLayers = [];
    foreach ($kinds as $kind) {
      array_push($kindLayers, ...array_map(strval(...), array_keys($kind->piece->tiles)));
    }
    $stacks = TilesetStack::readAll($data['stacks'] ?? [], $pieces, array_values(array_unique($kindLayers)), $context);
    $placeIcons = self::readPlaceIcons($data[self::PLACE_ICONS] ?? [], $pieces, $stacks, $context);
    return new self($id, $data['name'], $sheets, $above, $tables, $pieces, $missingArt, $shadows, $appearance, $kinds, $stacks,
      $placeIcons, $thickets);
  }

  /**
   * The place icons: a piece id for each place type id. Each is one of the
   * tileset's own pieces stamped whole with graphical tiles, so every copy
   * on a map is found by its tiles ({@see \Ichiloto\Engine\Field\MapGraphics::findPieceStampsCovering()}).
   * Whether each type is one of the project's is the Editor's to check.
   *
   * @param array<string, TilesetPiece> $pieces
   * @param array<string, TilesetStack> $stacks
   * @return array<string, string>
   */
  private static function readPlaceIcons(mixed $icons, array $pieces, array $stacks, string $context): array
  {
    if (!is_array($icons) || ($icons !== [] && array_is_list($icons))) {
      throw new InvalidArgumentException("{$context} placeIcons must be keyed by place type id.");
    }
    foreach ($icons as $type => $pieceId) {
      if (!is_string($type) || preg_match(MapLandmarkCatalog::ID_PATTERN, $type) !== 1) {
        throw new InvalidArgumentException("{$context} placeIcons: '{$type}' is not a stable place type id.");
      }
      $piece = is_string($pieceId) ? ($pieces[$pieceId] ?? null) : null;
      if ($piece === null) {
        throw new InvalidArgumentException("{$context} placeIcons: {$type} shows " . var_export($pieceId, true) . ', which is not one of its pieces.');
      }
      $stacked = array_any($stacks, static fn(TilesetStack $stack): bool => $stack->isStackedPiece($piece));
      $drawn = array_any($piece->tiles, static fn(array $rows): bool
        => array_any($rows, static fn(array $row): bool => array_any($row, static fn(string $tile): bool => $tile !== '0')));
      if ($piece->connects !== null || $stacked || !$drawn) {
        throw new InvalidArgumentException("{$context} placeIcons: {$type} shows piece {$pieceId}, which is not stamped whole with tiles.");
      }
    }
    return $icons;
  }

  /**
   * A terrain kind's own autotile is the thicket its kind names, so a
   * thicket's tiles never list one.
   *
   * @param array<string, TilesetThicket> $thickets
   * @param array<string, TerrainKind> $kinds
   */
  private static function assertThicketTilesAreNotKinds(array $thickets, array $kinds, string $context): void
  {
    foreach ($kinds as $kind) {
      foreach ($kind->art === null ? [] : $thickets as $thicket) {
        if (in_array($kind->art['tile'], $thicket->tiles, true)) {
          throw new InvalidArgumentException("{$context} thicket {$thicket->id} lists tile {$kind->art['tile']}, terrain kind {$kind->id}'s own autotile; "
            . 'name the thicket on the kind instead.');
        }
      }
    }
  }

  /** @return list<int> Flag identities, one per autotile kind. */
  private static function getIdentityList(mixed $ids, string $context): array
  {
    if (!is_array($ids) || !array_is_list($ids)) {
      throw new InvalidArgumentException("{$context} must be a list of tile identities.");
    }
    $flags = [];
    foreach ($ids as $id) {
      if (!is_int($id) || $id === TileId::EMPTY || !TileId::isValid($id)) {
        throw new InvalidArgumentException("{$context}: " . var_export($id, true) . ' is not an RPG Maker tile identity.');
      }
      $flags[TileId::getFlagId($id)] = true;
    }
    return array_keys($flags);
  }

  public function isAbove(int $id): bool
  {
    return in_array(TileId::getFlagId($id), $this->above, true);
  }

  public function isTable(int $id): bool
  {
    return in_array(TileId::getFlagId($id), $this->tables, true);
  }

  /** What a tile shows at ground level, or null when unknown ({@see TilesetAppearance::classify()}). */
  public function classifyAppearance(int $id): ?TileAppearance
  {
    return $this->appearance->classify($id, $this);
  }

  /**
   * The flag identities of the corner-edged kinds' autotiles, in kind order,
   * which is also the order they draw in where they share a tile layer: each
   * over those before it.
   *
   * @return list<int>
   */
  public function getCornerTiles(): array
  {
    return array_values(array_map(static fn(TerrainKind $kind): int => $kind->art['tile'],
      array_filter($this->kinds, static fn(TerrainKind $kind): bool => $kind->cornerEdged)));
  }

  /**
   * For each corner-edged kind in a join group, by its autotile's flag
   * identity, the flag identities of every kind in that group, itself
   * included, in kind order ({@see CornerShape::resolveLayer()}). Kinds in no
   * group are absent.
   *
   * @return array<int, list<int>>
   */
  public function getCornerJoins(): array
  {
    $groups = [];
    foreach ($this->kinds as $kind) {
      if ($kind->cornerEdged && $kind->joins !== null) {
        $groups[$kind->joins][] = $kind->art['tile'];
      }
    }
    $joins = [];
    foreach ($groups as $members) {
      foreach ($members as $tile) {
        $joins[$tile] = $members;
      }
    }
    return $joins;
  }

  /**
   * The corner sheets of the corner-edged kinds whose autotiles are listed,
   * read from the current files at the tileset's tile size. An unusable
   * sheet is reported and left out: its kind's cells show their terminal
   * glyphs, as a tile from an unusable sheet does.
   *
   * @param list<int> $tiles Flag identities of corner-edged kinds' autotiles.
   * @return array<int, CornerSheet> By flag identity, in kind order.
   */
  public function getUsableCornerSheets(string $assetRoot, int $tileSize, array $tiles): array
  {
    $usable = [];
    foreach ($this->kinds as $kind) {
      if (!$kind->cornerEdged || !in_array($kind->art['tile'], $tiles, true)) {
        continue;
      }
      try {
        $usable[$kind->art['tile']] = CornerSheet::read($kind, $assetRoot, $tileSize);
      } catch (RuntimeException | InvalidArgumentException $error) {
        Debug::warn("Tileset {$this->id} kind {$kind->id} corner sheet is unusable; its cells show terminal glyphs: " . $error->getMessage());
      }
    }
    return $usable;
  }

  /**
   * The sheets whose images fit their RPG Maker layout at one shared tile
   * size, read from the current files. An unusable sheet is reported and
   * left out; tiles that need it show their terminal glyphs instead.
   *
   * @return array{tileSize: int, sheets: array<string, string>}|null Null when no sheet is usable.
   */
  public function getUsableSheets(string $assetRoot): ?array
  {
    $tileSize = null;
    $usable = [];
    foreach ($this->sheets as $name => $asset) {
      $layout = TilesetSheet::from($name);
      try {
        $size = PngAssetPreflight::inspect($assetRoot, $asset);
        $sheetTile = intdiv($size['width'], $layout->getColumns());
        if ($size['width'] % $layout->getColumns() !== 0 || $sheetTile % 2 !== 0
          || $size['height'] !== $sheetTile * $layout->getRows()) {
          throw new RuntimeException(sprintf('%s is %dx%d; an %s sheet is %d x %d tiles of one even size, such as %dx%d at 48 pixels.',
            $asset, $size['width'], $size['height'], $name, $layout->getColumns(), $layout->getRows(),
            $layout->getColumns() * 48, $layout->getRows() * 48));
        }
        if ($tileSize !== null && $sheetTile !== $tileSize) {
          throw new RuntimeException("{$asset} has {$sheetTile}-pixel tiles, but this tileset's other sheets use {$tileSize}.");
        }
        $tileSize = $sheetTile;
        $usable[$name] = $asset;
      } catch (RuntimeException | InvalidArgumentException $error) {
        Debug::warn("Tileset {$this->id} sheet {$name} is unusable; its tiles show terminal glyphs: " . $error->getMessage());
      }
    }
    return $tileSize === null ? null : ['tileSize' => $tileSize, 'sheets' => $usable];
  }
}
