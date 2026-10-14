<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;

/** A removed row/column range and its author-confirmed post-removal landing. */
final readonly class MapRemoval implements SavedPositionEdit
{
  /** @param array{int, int} $to */
  private function __construct(
    public string $map,
    public string $axis,
    public int $at,
    public int $count,
    public array $to,
  )
  {
  }

  /** Validates one manifest `mapRemovals` entry. */
  public static function createFromArray(mixed $entry, string $where): self
  {
    if (! is_array($entry)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be an array with map, axis, at, count and to.', $where
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
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s at must be a non-negative integer.', $where));
    }

    $count = $entry['count'] ?? null;
    if (! is_int($count) || $count < 1) {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s count must be a positive integer.', $where));
    }

    $to = $entry['to'] ?? null;
    if (! is_array($to) || ! array_is_list($to) || count($to) !== 2
      || ! is_int($to[0]) || ! is_int($to[1]) || $to[0] < 0 || $to[1] < 0) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s to must be [x, y] with non-negative integers.', $where
      ));
    }

    return new self($map, $axis, $at, $count, $to);
  }

  public function applyTo(Vector2 $position): Vector2
  {
    $coordinate = $this->axis === 'x' ? $position->x : $position->y;
    if ($coordinate < $this->at) {
      return $position;
    }

    if ($coordinate - $this->at < $this->count) {
      return new Vector2(...$this->to);
    }

    return $this->axis === 'x'
      ? new Vector2($position->x - $this->count, $position->y)
      : new Vector2($position->x, $position->y - $this->count);
  }
}
