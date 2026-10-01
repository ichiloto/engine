<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use Ichiloto\Engine\Field\MapTileLayer;
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
  public const array FIELDS = ['name', 'layer', 'glyphs', 'tiles', 'connects'];
  /** Cells join the cells beside them across and down, like a wall or fence. */
  public const string LINES = 'lines';
  /** A line cell's shapes: joined only across, only down, or both ways (corners, junctions and lone posts). */
  public const array LINE_SHAPES = ['horizontal', 'vertical', 'corner'];
  private const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]*\z/';

  /** Cells across. */
  public int $width;
  /** Cells down. */
  public int $height;

  /**
   * @param list<list<string>> $glyphs One visible character per cell, by row; one cell for a connected piece.
   * @param array<string, list<list<string>>> $tiles Tile layer entries (`42`, `0`) by row, keyed by tile layer name.
   * @param array<string, string> $shapes A connected piece's glyph for each shape.
   * @param array<string, array<string, string>> $shapeTiles A connected piece's tile entry for each shape, keyed by tile layer name.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $layer,
    public array $glyphs,
    public array $tiles = [],
    public ?string $connects = null,
    public array $shapes = [],
    public array $shapeTiles = [],
  ) {
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
      if ($symbols === [] || array_filter($symbols, static fn(string $symbol): bool => TerminalText::displayWidth($symbol) !== 1) !== []) {
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
    return new self($id, $data['name'], $data['layer'], $glyphs, $tiles);
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
      if (!is_string($glyph) || TerminalText::symbolCount($glyph) !== 1 || TerminalText::displayWidth($glyph) !== 1 || trim($glyph) === '') {
        throw new InvalidArgumentException("{$context} {$shape} glyph must be one visible character, one terminal cell wide.");
      }
      $shapes[$shape] = $glyph;
    }
    if (count(array_unique($shapes)) !== count($shapes)) {
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
    return new self($id, $data['name'], $data['layer'], [[$shapes['corner']]], [], self::LINES, $shapes, $shapeTiles);
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
