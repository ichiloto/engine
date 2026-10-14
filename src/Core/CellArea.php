<?php

namespace Ichiloto\Engine\Core;

use InvalidArgumentException;

/**
 * An exact set of grid cells: the cells an event's marker occupies, in any
 * shape, connected or not. A rectangle is one case of it.
 *
 * Membership is by cell, so nothing between two placements of a marker
 * belongs to the area. Cells are kept in reading order (row by row, left to
 * right), which makes the first cell and every listing deterministic.
 *
 * @package Ichiloto\Engine\Core
 */
final class CellArea
{
  /** @var list<array{int, int}> The cells as [x, y], in reading order. */
  public readonly array $cells;

  /** The smallest rectangle holding every cell. */
  public readonly Rect $bounds;

  /** @var array<string, true> Cells keyed "x,y". */
  private readonly array $index;

  /** Whether the cells fill their bounds exactly. */
  public bool $isRectangle {
    get => count($this->cells) === $this->bounds->getWidth() * $this->bounds->getHeight();
  }

  /** The first cell in reading order: where the area is named to be. */
  public Vector2 $firstCell {
    get => new Vector2($this->cells[0][0], $this->cells[0][1]);
  }

  /**
   * @param list<array{int, int}> $cells Distinct cells as [x, y].
   */
  private function __construct(array $cells)
  {
    usort($cells, static fn(array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    $index = [];

    foreach ($cells as [$x, $y]) {
      $index["{$x},{$y}"] = true;
    }

    $xs = array_column($cells, 0);
    $ys = array_column($cells, 1);
    $this->cells = $cells;
    $this->index = $index;
    $this->bounds = new Rect(min($xs), min($ys), max($xs) - min($xs) + 1, max($ys) - min($ys) + 1);
  }

  /**
   * Builds an area from cells.
   *
   * @param iterable<array{int, int}> $cells Cells as [x, y]; repeats are merged.
   * @throws InvalidArgumentException When there are no cells or a cell is not two whole numbers.
   */
  public static function fromCells(iterable $cells): self
  {
    $distinct = [];

    foreach ($cells as $cell) {
      if (! is_array($cell) || ! array_is_list($cell) || count($cell) !== 2 || ! is_int($cell[0]) || ! is_int($cell[1])) {
        throw new InvalidArgumentException('An area cell must be [x, y] with whole numbers.');
      }

      $distinct["{$cell[0]},{$cell[1]}"] = $cell;
    }

    if ($distinct === []) {
      throw new InvalidArgumentException('An area needs at least one cell.');
    }

    return new self(array_values($distinct));
  }

  /** Builds the area every cell of a rectangle covers. */
  public static function fromRect(Rect $rect): self
  {
    $cells = [];

    for ($y = $rect->getY(); $y < $rect->getY() + $rect->getHeight(); $y++) {
      for ($x = $rect->getX(); $x < $rect->getX() + $rect->getWidth(); $x++) {
        $cells[] = [$x, $y];
      }
    }

    return self::fromCells($cells);
  }

  /**
   * Reads an area as event definitions hold it: `cells` as a list of [x, y],
   * or a rectangle as `x`, `y`, `width` and `height`.
   *
   * @param mixed $area The authored or resolved area.
   * @param string $where Where it is, for messages.
   * @throws InvalidArgumentException When it is neither shape.
   */
  public static function fromArray(mixed $area, string $where = 'area'): self
  {
    if (is_array($area) && array_key_exists('cells', $area)) {
      if (! is_array($area['cells'])) {
        throw new InvalidArgumentException("{$where} cells must be a list of [x, y].");
      }

      try {
        return self::fromCells($area['cells']);
      } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException("{$where}: {$exception->getMessage()}", previous: $exception);
      }
    }

    if (is_array($area) && is_int($area['x'] ?? null) && is_int($area['y'] ?? null)
      && is_int($area['width'] ?? null) && is_int($area['height'] ?? null)
      && $area['width'] > 0 && $area['height'] > 0) {
      return self::fromRect(new Rect($area['x'], $area['y'], $area['width'], $area['height']));
    }

    throw new InvalidArgumentException("{$where} needs cells as a list of [x, y], or whole-number x, y, width and height.");
  }

  /**
   * Returns the area as event definitions hold it.
   *
   * @return array{cells: list<array{int, int}>}
   */
  public function toArray(): array
  {
    return ['cells' => $this->cells];
  }

  /** Whether a position falls in one of the cells. */
  public function contains(Vector2 $point): bool
  {
    return $this->containsCell((int) floor($point->x), (int) floor($point->y));
  }

  /** Whether a cell belongs to the area. */
  public function containsCell(int $x, int $y): bool
  {
    return isset($this->index["{$x},{$y}"]);
  }

  /**
   * Splits the area into its separate placements: cells joined side by side
   * belong together. Ordered by each placement's first cell.
   *
   * @return list<self>
   */
  public function findPieces(): array
  {
    $seen = [];
    $pieces = [];

    foreach ($this->cells as [$startX, $startY]) {
      if (isset($seen["{$startX},{$startY}"])) {
        continue;
      }

      $piece = [];
      $queue = [[$startX, $startY]];
      $seen["{$startX},{$startY}"] = true;

      while ($queue !== []) {
        [$x, $y] = array_shift($queue);
        $piece[] = [$x, $y];

        foreach ([[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]] as [$nx, $ny]) {
          if ($this->containsCell($nx, $ny) && ! isset($seen["{$nx},{$ny}"])) {
            $seen["{$nx},{$ny}"] = true;
            $queue[] = [$nx, $ny];
          }
        }
      }

      $pieces[] = new self($piece);
    }

    return $pieces;
  }

  /**
   * Returns the cell nearest the middle of the bounds, so a marker that
   * fills its bounds is centred and any other shape stays on its own cells.
   * Ties go to the earlier cell in reading order.
   */
  public function findCenterCell(): Vector2
  {
    $centerX = $this->bounds->getX() + intdiv($this->bounds->getWidth() - 1, 2);
    $centerY = $this->bounds->getY() + intdiv($this->bounds->getHeight() - 1, 2);
    $best = $this->cells[0];
    $bestDistance = PHP_INT_MAX;

    foreach ($this->cells as $cell) {
      $distance = ($cell[0] - $centerX) ** 2 + ($cell[1] - $centerY) ** 2;

      if ($distance < $bestDistance) {
        [$best, $bestDistance] = [$cell, $distance];
      }
    }

    return new Vector2($best[0], $best[1]);
  }
}
