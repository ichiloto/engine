<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;

/**
 * One declarative map edit a save must follow: blank rows or columns were
 * inserted into a map, so every saved coordinate at or beyond the insertion
 * line on that axis moved by the inserted count.
 */
final readonly class MapShift implements SavedPositionEdit
{
  private function __construct(
    public string $map,
    public string $axis,
    public int $at,
    public int $by,
  )
  {
  }

  /**
   * Validates one manifest `mapShifts` entry.
   */
  public static function fromArray(mixed $entry, string $where): self
  {
    if (! is_array($entry)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be an array with map, axis, at and by.',
        $where
      ));
    }

    $map = is_string($entry['map'] ?? null) ? trim($entry['map']) : '';

    if ($map === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s map must be a non-empty string.', $where));
    }

    $axis = $entry['axis'] ?? null;

    if ($axis !== 'x' && $axis !== 'y') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s axis must be "x" or "y".', $where));
    }

    $at = $entry['at'] ?? null;

    if (! is_int($at) || $at < 0) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s at must be a non-negative integer.',
        $where
      ));
    }

    $by = $entry['by'] ?? null;

    if (! is_int($by) || $by < 1) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s by must be a positive integer.', $where));
    }

    return new self($map, $axis, $at, $by);
  }

  /**
   * Returns the position after this shift; positions before the line keep
   * their coordinates.
   */
  public function applyTo(Vector2 $position): Vector2
  {
    if ($this->axis === 'x') {
      return $position->x >= $this->at ? new Vector2($position->x + $this->by, $position->y) : $position;
    }

    return $position->y >= $this->at ? new Vector2($position->x, $position->y + $this->by) : $position;
  }
}
