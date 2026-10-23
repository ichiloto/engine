<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/**
 * Tile layers whose tiles stack, so whole objects drawn on them can overlap,
 * such as the peaks and hill crests over a world map's mountain and hill
 * kinds. A cell of a stack holds up to one tile per layer, and its tiles are
 * drawn bottom to top in depth order: an object whose ground row (its
 * footprint's bottom row) lies further south draws in front, as the field
 * orders characters, and of two on one row the one further east. A piece
 * that stacks ({@see isStackedPiece()}) is pure decoration: its tiles go on
 * the stack's first layer, it writes no glyph, occupancy, effect or kept
 * layer, so it never changes passage, encounters or atlas roles. Every tile
 * of a stack's pieces belongs to one piece cell, so the objects a cell holds
 * are read back from its tiles and never stored twice.
 *
 * Declared in a tileset as `'stacks' => ['relief' => ['layers' => ['relief-1', 'relief-2', 'relief-3']]]`.
 */
final readonly class TilesetStack
{
  public const array FIELDS = ['layers'];
  public const int MAX_LAYERS = 16;
  private const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_-]*\z/';

  /**
   * @param list<string> $layers Tile layer names, bottom to top.
   * @param array<int, array{piece: TilesetPiece, column: int, row: int}> $cells Each tile of the stack's pieces, by tile id.
   */
  public function __construct(
    public string $id,
    public array $layers,
    private array $cells = [],
  ) {}

  /** The layer a stacked piece's tiles are authored on. */
  public function getBaseLayer(): string
  {
    return $this->layers[0];
  }

  /** Whether a piece stacks here: its tiles go on this stack's first layer. */
  public function isStackedPiece(TilesetPiece $piece): bool
  {
    return $piece->connects === null && isset($piece->tiles[$this->getBaseLayer()]);
  }

  /**
   * The piece cell a tile of this stack draws, or null for a tile no
   * stacked piece draws.
   *
   * @return array{piece: TilesetPiece, column: int, row: int}|null
   */
  public function locate(int $tileId): ?array
  {
    return $this->cells[$tileId] ?? null;
  }

  /**
   * A cell's tiles once a stacked piece's tile is laid there, bottom to top
   * in depth order. Tiles no stacked piece draws keep their place below the
   * pieces' tiles; a tile the cell already holds is not laid twice.
   *
   * @param list<int> $held The cell's tiles on the stack's layers, bottom to top, empty ones left out.
   * @return list<int>
   * @throws InvalidArgumentException When the tile is not one of this stack's, or the cell would hold more tiles than the stack has layers.
   */
  public function layTile(array $held, int $tileId, int $x, int $y): array
  {
    if ($this->locate($tileId) === null) {
      throw new InvalidArgumentException("Tile {$tileId} is drawn by none of stack {$this->id}'s pieces.");
    }
    if (in_array($tileId, $held, true)) {
      return $held;
    }
    $foreign = array_values(array_filter($held, fn(int $id): bool => $this->locate($id) === null));
    $placed = array_values(array_filter([...$held, $tileId], fn(int $id): bool => $this->locate($id) !== null));
    usort($placed, fn(int $a, int $b): int => $this->getDepth($a, $x, $y) <=> $this->getDepth($b, $x, $y));
    $laid = [...$foreign, ...$placed];
    if (count($laid) > count($this->layers)) {
      throw new InvalidArgumentException(sprintf('(%d, %d) would hold %d tiles of stack %s, which has %d layers.',
        $x, $y, count($laid), $this->id, count($this->layers)));
    }
    return $laid;
  }

  /**
   * A stacked tile's depth in a cell: its piece's ground row, then twice its
   * footprint's centre column, so further south and then further east draws
   * in front. Tiles are kept apart by id when two copies of a piece share
   * both, which only exact duplicates do.
   *
   * @return array{0: int, 1: int, 2: int}
   */
  private function getDepth(int $tileId, int $x, int $y): array
  {
    ['piece' => $piece, 'column' => $column, 'row' => $row] = $this->cells[$tileId];
    return [$y - $row + $piece->height - 1, 2 * ($x - $column) + $piece->width, $tileId];
  }

  /**
   * Reads a tileset's `stacks` against its pieces and terrain kinds.
   *
   * @param array<string, TilesetPiece> $pieces
   * @param list<string> $kindLayers Tile layers its terrain kinds paint.
   * @return array<string, self> Keyed by stack id.
   * @throws InvalidArgumentException
   */
  public static function readAll(mixed $data, array $pieces, array $kindLayers, string $context): array
  {
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new InvalidArgumentException("{$context} stacks must be keyed by stack id.");
    }
    $stacks = [];
    $owners = [];
    foreach ($data as $id => $definition) {
      $id = (string)$id;
      $stackContext = "{$context} stack {$id}";
      if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $id) !== 1) {
        throw new InvalidArgumentException("{$stackContext}: a stack id is lowercase letters, digits, hyphens or underscores.");
      }
      if (!is_array($definition) || array_diff(array_keys($definition), self::FIELDS) !== []) {
        throw new InvalidArgumentException("{$stackContext} must be an array of " . implode(', ', self::FIELDS) . '.');
      }
      $layers = $definition['layers'] ?? null;
      if (!is_array($layers) || !array_is_list($layers) || count($layers) < 2 || count($layers) > self::MAX_LAYERS) {
        throw new InvalidArgumentException("{$stackContext} layers must list 2 to " . self::MAX_LAYERS . ' tile layers, bottom to top.');
      }
      foreach ($layers as $layer) {
        if (!is_string($layer) || preg_match(self::NAME_PATTERN, $layer) !== 1) {
          throw new InvalidArgumentException("{$stackContext} layers must name valid tile layers.");
        }
        if (isset($owners[$layer])) {
          throw new InvalidArgumentException("{$stackContext}: tile layer {$layer} is listed twice; a tile layer belongs to one stack once.");
        }
        if (in_array($layer, $kindLayers, true)) {
          throw new InvalidArgumentException("{$stackContext}: terrain kinds paint tile layer {$layer}; a stack's layers hold its pieces alone.");
        }
        $owners[$layer] = $id;
      }
      $stacks[$id] = new self($id, $layers);
    }
    $cells = [];
    foreach ($pieces as $piece) {
      $layers = array_keys($piece->connects === null ? $piece->tiles : $piece->shapeTiles);
      $touched = array_values(array_intersect($layers, array_keys($owners)));
      if ($touched === []) {
        continue;
      }
      $stack = $stacks[$owners[$touched[0]]];
      $pieceContext = "{$context} piece {$piece->id}";
      if ($piece->connects !== null || $layers !== [$stack->getBaseLayer()]) {
        throw new InvalidArgumentException("{$pieceContext}: a piece on stack {$stack->id} puts its tiles on its first layer, "
          . "{$stack->getBaseLayer()}, and on no other tile layer; the stack lays them across its layers.");
      }
      if (array_any($piece->glyphs, static fn(array $row): bool => array_any($row, static fn(string $glyph): bool => $glyph !== ' '))
        || $piece->occupancy !== null || $piece->effect !== null || $piece->keeps !== []) {
        throw new InvalidArgumentException("{$pieceContext}: a piece on stack {$stack->id} is decoration only: spaces for glyphs, "
          . 'and no occupancy, effect or kept layers.');
      }
      foreach ($piece->tiles[$stack->getBaseLayer()] as $row => $entries) {
        foreach ($entries as $column => $entry) {
          if (!ctype_digit($entry)) {
            throw new InvalidArgumentException("{$pieceContext} tile {$column}, {$row} must be a plain tile id on a stack.");
          }
          $tileId = (int)$entry;
          if ($tileId === TileId::EMPTY) {
            continue;
          }
          if (isset($cells[$stack->id][$tileId])) {
            $other = $cells[$stack->id][$tileId];
            throw new InvalidArgumentException(sprintf('%s tile %d, %d: tile %d already draws piece %s cell %d, %d. '
              . 'Each tile of a stack draws one piece cell, so a cell\'s objects can be read from its tiles.',
              $pieceContext, $column, $row, $tileId, $other['piece']->id, $other['column'], $other['row']));
          }
          $cells[$stack->id][$tileId] = ['piece' => $piece, 'column' => $column, 'row' => $row];
        }
      }
    }
    return array_map(static fn(self $stack): self => new self($stack->id, $stack->layers, $cells[$stack->id] ?? []), $stacks);
  }
}
