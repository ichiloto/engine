<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * A whole item a map is built from, such as a bed: its terminal glyphs on one
 * gameplay layer and, optionally, its RPG Maker tiles on named tile layers,
 * over the same footprint of terminal cells. Editors stamp a piece whole; the
 * game only ever reads the glyphs and tiles it left behind, so collision
 * still comes from the glyphs alone. A space glyph and a `0` tile leave that
 * cell as it was.
 *
 * A connected piece (`'connects' => 'lines'`), such as a wall, is drawn one
 * cell at a time and joins the cells of the same piece beside it: each cell
 * takes the glyph and tiles of the shape its neighbours give it, and drawing
 * or erasing a cell reshapes the cells beside it.
 */
final readonly class TilesetPiece
{
  public const array FIELDS = ['name', 'layer', 'glyphs', 'tiles', 'connects', 'effect', 'keeps'];
  /** Cells join the cells beside them across and down, like a wall or fence. */
  public const string LINES = 'lines';
  /** A line cell's shapes: joined only across, only down, or both ways (corners, junctions and lone posts). */
  public const array LINE_SHAPES = ['horizontal', 'vertical', 'corner'];
  private const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]*\z/';

  /** Cells across. */
  public int $width;
  /** Cells down. */
  public int $height;
  /** @var list<list<string>> Styled display cells, using the same contract as MapLayer::grid. */
  public array $grid;
  /** @var list<list<string>> Plain glyphs; footprint and collision never depend on style. */
  public array $glyphs;
  /** @var array<string, string> Styled display cell for each connected shape. */
  public array $shapeGrid;
  /** @var array<string, string> Plain connected shapes; membership never depends on style. */
  public array $shapes;
  /** @var array<string, string> Authored source for each connected shape. */
  private array $sourceShapeGrid;
  /** @var list<list<string>> */
  private array $sourceCells;
  /** @var list<string> Existing tiles on these layers remain beneath a stamped piece. */
  public array $keeps;

  /**
   * @param list<list<string>> $glyphs One visible character per cell, optionally styled, by row; one cell for a connected piece.
   * @param array<string, list<list<string>>> $tiles Tile layer entries (`42`, `0`) by row, keyed by tile layer name.
   * @param array<string, string> $shapes A connected piece's optionally styled glyph for each shape.
   * @param array<string, array<string, string>> $shapeTiles A connected piece's tile entry for each shape, keyed by tile layer name.
   * @param list<string>|null $sourceRows Original rows, when constructing from row-based authored data.
   * @param array<string, string>|null $sourceShapeGrid Original connected shape strings, before display formatting.
   * @param list<string> $keeps Tile layers preserved under the stamped footprint, never layers this piece writes.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $layer,
    array $glyphs,
    public array $tiles = [],
    public ?string $connects = null,
    array $shapes = [],
    public array $shapeTiles = [],
    public ?string $effect = null,
    private ?array $sourceRows = null,
    ?array $sourceShapeGrid = null,
    array $keeps = [],
  ) {
    $this->keeps = self::readKeptLayers($keeps, array_keys($tiles + $shapeTiles), $connects, "Piece {$id}");
    $this->sourceCells = $glyphs;
    $this->sourceShapeGrid = $sourceShapeGrid ?? $shapes;
    $this->grid = array_map(static fn(array $row): array => array_map(
      static fn(string $cell): string => implode('', TerminalText::visibleSymbols($cell)), $row), $glyphs);
    $this->glyphs = array_map(static fn(array $row): array => array_map(TerminalText::stripAnsi(...), $row), $this->grid);
    $this->shapeGrid = array_map(static fn(string $cell): string => implode('', TerminalText::visibleSymbols($cell)), $shapes);
    $this->shapes = array_map(TerminalText::stripAnsi(...), $this->shapeGrid);
    $this->height = count($glyphs);
    $this->width = count($glyphs[0] ?? []);
  }

  /**
   * Reads one entry of a tileset's `pieces`, for example:
   * `'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '='], 'tiles' => ['furniture' => ['32', '40']]]`
   * or a connected piece:
   * `'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
   *   'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']]`.
   */
  public static function fromArray(string $id, mixed $data, string $context): self
  {
    $context = "{$context} piece {$id}";
    if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
      throw new InvalidArgumentException("{$context}: a piece id is lowercase letters, digits, hyphens or underscores.");
    }
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException("{$context} must be an array of " . implode(', ', self::FIELDS) . '.');
    }
    if (!is_string($data['name'] ?? null) || trim($data['name']) === '') {
      throw new InvalidArgumentException("{$context} needs a name.");
    }
    if (!is_string($data['layer'] ?? null) || preg_match(self::NAME_PATTERN, $data['layer']) !== 1) {
      throw new InvalidArgumentException("{$context} needs the name of the gameplay layer its glyphs go on.");
    }
    $connects = $data['connects'] ?? null;
    $effect = $data['effect'] ?? null;
    if (array_key_exists('effect', $data)) {
      if (!is_string($effect)) { throw new InvalidArgumentException("{$context} effect must name an effect timeline."); }
      EffectTimelineLibrary::assertId($effect);
      if ($connects !== null) { throw new InvalidArgumentException("{$context} connected pieces do not declare effects yet."); }
    }
    if ($connects !== null && $connects !== self::LINES) {
      throw new InvalidArgumentException("{$context} connects must be '" . self::LINES . "'.");
    }
    $layers = $data['tiles'] ?? [];
    if (!is_array($layers)) {
      throw new InvalidArgumentException("{$context} tiles must be keyed by tile layer name.");
    }
    foreach (array_keys($layers) as $layer) {
      if (!is_string($layer) || preg_match(self::NAME_PATTERN, $layer) !== 1) {
        throw new InvalidArgumentException("{$context} tiles must be keyed by tile layer name.");
      }
    }
    $keeps = self::readKeptLayers(array_key_exists('keeps', $data) ? $data['keeps'] : [], array_keys($layers), $connects, $context);
    if ($connects === self::LINES) {
      return self::readConnected($id, $data, $layers, $context);
    }
    $rows = $data['glyphs'] ?? null;
    if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
      throw new InvalidArgumentException("{$context} needs its glyphs as a list of rows.");
    }
    $glyphs = [];
    foreach ($rows as $y => $row) {
      $symbols = is_string($row) ? TerminalText::visibleSymbols($row) : [];
      if ($symbols === [] || array_filter($symbols, static fn(string $symbol): bool => !self::isCellGlyph($symbol)) !== []) {
        throw new InvalidArgumentException("{$context} glyph row {$y} must be one or more characters, each one terminal cell wide.");
      }
      $glyphs[] = $symbols;
    }
    $width = count($glyphs[0]);
    if (array_filter($glyphs, static fn(array $row): bool => count($row) !== $width) !== []) {
      throw new InvalidArgumentException("{$context} glyph rows must all be {$width} cells wide.");
    }
    $tiles = [];
    foreach ($layers as $layer => $entries) {
      if (!is_array($entries) || !array_is_list($entries) || array_filter($entries, static fn(mixed $row): bool => !is_string($row)) !== []) {
        throw new InvalidArgumentException("{$context} tiles {$layer} must be rows of tile entries.");
      }
      // The same entries a tile layer file holds, read by the same rules.
      $cells = new MapTileLayer($layer, 0, "{$context} tiles {$layer}", implode("\n", $entries))->getEntries();
      if (count($cells) !== count($glyphs) || array_filter($cells, static fn(array $row): bool => count($row) !== $width) !== []) {
        throw new InvalidArgumentException(sprintf('%s tiles %s must match its glyphs: %d rows of %d cells.', $context, $layer, count($glyphs), $width));
      }
      $tiles[$layer] = $cells;
    }
    if ($effect !== null && ($tiles === [] || !array_any($tiles, static fn(array $rows): bool => array_any($rows,
      static fn(array $row): bool => array_any($row, static fn(string $tile): bool => $tile !== '0'))))) {
      throw new InvalidArgumentException("{$context} effect needs graphical tiles to identify its stamped instances.");
    }
    return new self($id, $data['name'], $data['layer'], $glyphs, $tiles, effect: $effect, sourceRows: $rows, keeps: $keeps);
  }

  /** @param list<array-key> $writtenLayers @return list<string> */
  private static function readKeptLayers(mixed $keeps, array $writtenLayers, ?string $connects, string $context): array
  {
    if (!is_array($keeps) || !array_is_list($keeps)) {
      throw new InvalidArgumentException("{$context} keeps must be a list of tile layer names.");
    }
    $names = [];
    foreach ($keeps as $name) {
      if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
        throw new InvalidArgumentException("{$context} keeps must name valid tile layers.");
      }
      if (isset($names[$name]) || in_array($name, $writtenLayers, true)) {
        throw new InvalidArgumentException("{$context} keeps must name distinct layers this piece does not write.");
      }
      $names[$name] = true;
    }
    if ($names !== [] && $connects !== null) {
      throw new InvalidArgumentException("{$context} connected pieces do not preserve underlying tile layers yet.");
    }
    return array_keys($names);
  }

  /** @param array<array-key, mixed> $data @param array<array-key, mixed> $layers */
  private static function readConnected(string $id, array $data, array $layers, string $context): self
  {
    $glyphs = $data['glyphs'] ?? null;
    if (!is_array($glyphs) || array_diff(self::LINE_SHAPES, array_keys($glyphs)) !== [] || array_diff(array_keys($glyphs), self::LINE_SHAPES) !== []) {
      throw new InvalidArgumentException("{$context} glyphs must name one glyph for each of " . implode(', ', self::LINE_SHAPES) . '.');
    }
    $shapes = [];
    foreach (self::LINE_SHAPES as $shape) {
      $glyph = $glyphs[$shape];
      $symbols = is_string($glyph) ? TerminalText::visibleSymbols($glyph) : [];
      if (count($symbols) !== 1 || !self::isCellGlyph($symbols[0]) || trim(TerminalText::stripAnsi($symbols[0])) === '') {
        throw new InvalidArgumentException("{$context} {$shape} glyph must be one visible character, one terminal cell wide.");
      }
      $shapes[$shape] = $symbols[0];
    }
    if (count(array_unique(array_map(TerminalText::stripAnsi(...), $shapes))) !== count($shapes)) {
      throw new InvalidArgumentException("{$context} needs a different glyph for each shape, so its cells can be told apart.");
    }
    $shapeTiles = [];
    foreach ($layers as $layer => $entries) {
      $entries = is_string($entries) ? array_fill_keys(self::LINE_SHAPES, $entries) : $entries;
      if (!is_array($entries) || array_diff(self::LINE_SHAPES, array_keys($entries)) !== [] || array_diff(array_keys($entries), self::LINE_SHAPES) !== []) {
        throw new InvalidArgumentException("{$context} tiles {$layer} must be one tile entry, or one for each of " . implode(', ', self::LINE_SHAPES) . '.');
      }
      foreach (self::LINE_SHAPES as $shape) {
        if (!is_string($entries[$shape])) {
          throw new InvalidArgumentException("{$context} tiles {$layer} {$shape} must be a tile entry.");
        }
        $cells = new MapTileLayer($layer, 0, "{$context} tiles {$layer} {$shape}", $entries[$shape])->getEntries();
        if (count($cells) !== 1 || count($cells[0]) !== 1) {
          throw new InvalidArgumentException("{$context} tiles {$layer} {$shape} must be a single tile entry.");
        }
        $shapeTiles[$layer][$shape] = $cells[0][0];
      }
    }
    return new self($id, $data['name'], $data['layer'], [[$shapes['corner']]], [], self::LINES, $shapes, $shapeTiles,
      sourceRows: [$glyphs['corner']], sourceShapeGrid: $glyphs);
  }

  private static function isCellGlyph(string $symbol): bool
  {
    return TerminalText::displayWidth($symbol) === 1
      && preg_match('/\p{Cc}/u', TerminalText::stripAnsi($symbol)) !== 1;
  }

  /** @return list<list<string>> Independent authored source cells, not display ANSI converted to markup. */
  public function getSourceGrid(): array
  {
    return $this->sourceRows === null
      ? array_map(static fn(array $row): array => array_map(self::getSourceCell(...), $row), $this->sourceCells)
      : array_map(TerminalText::getSourceSymbols(...), $this->sourceRows);
  }

  /** @return array<string, string> Authored source for each connected shape. */
  public function getSourceShapeGrid(): array
  {
    return array_map(self::getSourceCell(...), $this->sourceShapeGrid);
  }

  private static function getSourceCell(string $source): string
  {
    $cells = TerminalText::getSourceSymbols($source);
    if (count($cells) !== 1) { throw new InvalidArgumentException('Piece source cell must remain one independently authored glyph.'); }
    return $cells[0];
  }

  /** Whether a glyph is one of this connected piece's shapes, so its cell joins the piece. */
  public function isMember(string $glyph): bool
  {
    return $this->connects !== null && in_array(TerminalText::stripAnsi($glyph), $this->shapes, true);
  }

  /** The shape a line cell takes from which of its neighbours belong to the same piece. */
  public function getLineShape(bool $north, bool $east, bool $south, bool $west): string
  {
    $across = $east || $west;
    $down = $north || $south;
    return match (true) {
      $across && !$down => 'horizontal',
      $down && !$across => 'vertical',
      default => 'corner',
    };
  }
}
