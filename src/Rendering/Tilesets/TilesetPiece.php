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
 */
final readonly class TilesetPiece
{
  public const array FIELDS = ['name', 'layer', 'glyphs', 'tiles'];
  private const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]*\z/';

  /** Cells across. */
  public int $width;
  /** Cells down. */
  public int $height;

  /**
   * @param list<list<string>> $glyphs One visible character per cell, by row.
   * @param array<string, list<list<string>>> $tiles Tile layer entries (`42`, `42L`, `0`) by row, keyed by tile layer name.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $layer,
    public array $glyphs,
    public array $tiles = [],
  ) {
    $this->height = count($glyphs);
    $this->width = count($glyphs[0] ?? []);
  }

  /**
   * Reads one entry of a tileset's `pieces`, for example:
   * `'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '='], 'tiles' => ['furniture' => ['32', '40']]]`.
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
    $layers = $data['tiles'] ?? [];
    if (!is_array($layers)) {
      throw new InvalidArgumentException("{$context} tiles must map tile layer names to rows of tile entries.");
    }
    foreach ($layers as $layer => $entries) {
      if (!is_string($layer) || preg_match(self::NAME_PATTERN, $layer) !== 1 || !is_array($entries) || !array_is_list($entries)
        || array_filter($entries, static fn(mixed $row): bool => !is_string($row)) !== []) {
        throw new InvalidArgumentException("{$context} tiles must map tile layer names to rows of tile entries.");
      }
      // The same entries a tile layer file holds, read by the same rules.
      $read = new MapTileLayer($layer, 0, "{$context} tiles {$layer}", implode("\n", $entries));
      $cells = $read->getEntries();
      if (count($cells) !== count($glyphs) || array_filter($cells, static fn(array $row): bool => count($row) !== $width) !== []) {
        throw new InvalidArgumentException(sprintf('%s tiles %s must match its glyphs: %d rows of %d cells.', $context, $layer, count($glyphs), $width));
      }
      $tiles[$layer] = $cells;
    }
    return new self($id, $data['name'], $data['layer'], $glyphs, $tiles);
  }
}
