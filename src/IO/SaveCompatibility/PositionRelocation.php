<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;

/**
 * One declarative map edit a save must follow: authored content (an NPC,
 * furniture, a wall) now occupies cells a player could have saved on, so a
 * position on one of those cells moves to a landing the author chose.
 *
 * The cells and landing are frozen in the manifest; loading a save never
 * consults the mutable map to decide where a player goes.
 */
final readonly class PositionRelocation implements SavedPositionEdit
{
  /**
   * @param list<array{int, int}> $cells
   * @param array{int, int} $to
   */
  private function __construct(
    public string $map,
    public array $cells,
    public array $to,
  )
  {
  }

  /**
   * Validates one manifest `relocations` entry.
   */
  public static function fromArray(mixed $entry, string $where): self
  {
    if (! is_array($entry)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be an array with map, cells and to.',
        $where
      ));
    }

    $map = is_string($entry['map'] ?? null) ? trim($entry['map']) : '';

    if ($map === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s map must be a non-empty string.', $where));
    }

    $rawCells = $entry['cells'] ?? null;

    if (! is_array($rawCells) || $rawCells === [] || ! array_is_list($rawCells)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s cells must be a non-empty list.', $where));
    }

    $cells = [];

    foreach ($rawCells as $index => $cell) {
      $cell = self::readCell($cell, sprintf('%s cells[%d]', $where, $index));

      if (in_array($cell, $cells, true)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s cells[%d] repeats [%d, %d].',
          $where,
          $index,
          ...$cell
        ));
      }

      $cells[] = $cell;
    }

    $to = self::readCell($entry['to'] ?? null, "{$where} to");

    if (in_array($to, $cells, true)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s to [%d, %d] is one of the cells it moves players off.',
        $where,
        ...$to
      ));
    }

    return new self($map, $cells, $to);
  }

  public function applyTo(Vector2 $position): Vector2
  {
    foreach ($this->cells as [$x, $y]) {
      if ($position->x == $x && $position->y == $y) {
        return new Vector2(...$this->to);
      }
    }

    return $position;
  }

  /** @return array{int, int} */
  private static function readCell(mixed $cell, string $where): array
  {
    if (! is_array($cell) || ! array_is_list($cell) || count($cell) !== 2
      || ! is_int($cell[0]) || ! is_int($cell[1]) || $cell[0] < 0 || $cell[1] < 0) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be [x, y] with non-negative integers.',
        $where
      ));
    }

    return [$cell[0], $cell[1]];
  }
}
