<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/**
 * A project tileset: RPG Maker sheets by name, the graphical flags RPG Maker
 * keeps per tile, and the pieces maps are built from with it. Passage
 * settings do not exist here: collision always comes from the terminal
 * layers. Loaded from `Data/Tilesets/<id>.php`.
 */
final readonly class Tileset
{
  public const string DIRECTORY = 'Data/Tilesets';
  private const array FIELDS = ['name', 'sheets', 'above', 'tables', 'pieces'];

  /**
   * @param array<string, string> $sheets Asset-relative PNG paths keyed by sheet name.
   * @param list<int> $above Tile identities drawn above characters (every shape of an autotile kind).
   * @param list<int> $tables A2 autotile identities drawn as tables.
   * @param array<string, TilesetPiece> $pieces Whole items, keyed by piece id, in authored order.
   */
  public function __construct(
    public string $id,
    public string $name,
    public array $sheets,
    public array $above = [],
    public array $tables = [],
    public array $pieces = [],
  ) {}

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
    return new self($id, $data['name'], $sheets, $above, $tables, $pieces);
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
