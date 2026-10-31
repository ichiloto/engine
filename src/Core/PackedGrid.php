<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use InvalidArgumentException;

/**
 * Rows of small non-negative integers held as packed bytes: one, two or four
 * bytes per cell, the fewest that hold the largest value. A map's per-cell
 * grids (palette indices of its glyphs, tile identities, layer owners) are
 * kept this way, so a world map costs bytes per cell rather than a PHP array
 * slot per cell. Rows may differ in length, as legacy map rows do.
 *
 * Read one cell with {@see getValueAt()}, one row with {@see getRowValues()},
 * or every row with {@see readRows()}, which builds the arrays it returns.
 */
final readonly class PackedGrid
{
  /** The largest value a cell can hold. */
  public const int MAX_VALUE = 0xFFFFFFFF;

  /** The pack format of a cell, by its width in bytes. */
  private const array FORMATS = [1 => 'C*', 2 => 'v*', 4 => 'V*'];

  /** Number of rows. */
  public int $height;

  /** The longest row's cell count. */
  public int $width;

  /** @param list<string> $rows Packed rows. */
  private function __construct(private array $rows, private int $bytes)
  {
    $this->height = count($rows);
    $width = 0;
    foreach ($rows as $row) { $width = max($width, intdiv(strlen($row), $bytes)); }
    $this->width = $width;
  }

  /**
   * Packs rows of values, each between 0 and {@see MAX_VALUE}. Rows are read
   * one at a time, so a generator never holds the whole grid as arrays.
   *
   * @param iterable<int, list<int>> $rows In row order.
   * @throws InvalidArgumentException When a value is out of range.
   */
  public static function fromRows(iterable $rows): self
  {
    // Rows are packed as they arrive, as narrow as the values so far allow; a wider value
    // repacks the rows before it, at most twice, so most rows are packed once.
    $packed = [];
    $bytes = 1;
    foreach ($rows as $row) {
      $max = 0;
      foreach ($row as $value) {
        if (!is_int($value) || $value < 0 || $value > self::MAX_VALUE) {
          throw new InvalidArgumentException('A packed grid holds integers from 0 to ' . self::MAX_VALUE . '.');
        }
        if ($value > $max) { $max = $value; }
      }
      $width = $max <= 0xFF ? 1 : ($max <= 0xFFFF ? 2 : 4);
      if ($width > $bytes) {
        foreach ($packed as $y => $previous) {
          $packed[$y] = $previous === '' ? '' : pack(self::FORMATS[$width], ...unpack(self::FORMATS[$bytes], $previous));
        }
        $bytes = $width;
      }
      $packed[] = $row === [] ? '' : pack(self::FORMATS[$bytes], ...$row);
    }
    return new self($packed, $bytes);
  }

  /** The value at a cell, or null outside the grid or past the end of its row. */
  public function getValueAt(int $x, int $y): ?int
  {
    $row = $this->rows[$y] ?? null;
    if ($row === null || $x < 0) {
      return null;
    }
    $offset = $x * $this->bytes;
    if ($offset >= strlen($row)) {
      return null;
    }
    return match ($this->bytes) {
      1 => ord($row[$offset]),
      2 => ord($row[$offset]) | ord($row[$offset + 1]) << 8,
      default => unpack('V', $row, $offset)[1],
    };
  }

  /** The number of cells in a row; 0 outside the grid. */
  public function getRowLength(int $y): int
  {
    return intdiv(strlen($this->rows[$y] ?? ''), $this->bytes);
  }

  /** @return list<int> A row's values; empty outside the grid. */
  public function getRowValues(int $y): array
  {
    $row = $this->rows[$y] ?? '';
    return $row === '' ? [] : array_values(unpack(self::FORMATS[$this->bytes], $row));
  }

  /** @return list<list<int>> Every row's values, built for the caller. */
  public function readRows(): array
  {
    $rows = [];
    foreach (array_keys($this->rows) as $y) { $rows[] = $this->getRowValues($y); }
    return $rows;
  }
}
