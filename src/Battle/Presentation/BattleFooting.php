<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * Where on an arena's picture each side's battlers may plant their feet: a
 * polygon of canvas points per side (the dirt of a road, not its grass verge
 * or rocks). A side with no polygon may stand anywhere on its area of the
 * ground. Staging fits feet into it and spreads an arranged troop along its
 * shape ({@see BattleStaging}). Graphical presentation only; the Terminal
 * battle never reads it.
 */
final readonly class BattleFooting
{
  /** @var list<array{0: float, 1: float}> */
  public array $enemies;
  /** @var list<array{0: float, 1: float}> */
  public array $party;

  /**
   * @param list<array{0: float, 1: float}> $enemies The enemies' footing polygon, or none.
   * @param list<array{0: float, 1: float}> $party The party's footing polygon, or none.
   */
  public function __construct(array $enemies = [], array $party = [])
  {
    $this->enemies = self::getValidatedPolygon($enemies, 'enemies');
    $this->party = self::getValidatedPolygon($party, 'party');
  }

  /** @return list<array{0: float, 1: float}> */
  public function getPolygon(bool $party): array
  {
    return $party ? $this->party : $this->enemies;
  }

  /**
   * The stretch of footing across a canvas height: the widest run of the
   * polygon there, its edges included, or null where the polygon does not
   * reach. With no polygon the side has no footing limit, also null.
   *
   * @param list<array{0: float, 1: float}> $polygon
   * @return array{0: float, 1: float}|null
   */
  public static function getSpan(array $polygon, float $y): ?array
  {
    if ($polygon === []) { return null; }
    // Scanning with edges closed at their top, then at their bottom, counts every vertex once per scan
    // and keeps the polygon's own top and bottom rows as footing.
    $widest = null;
    foreach ([false, true] as $closedBelow) {
      $crossings = [];
      $count = count($polygon);
      for ($index = 0; $index < $count; $index++) {
        [$x1, $y1] = $polygon[$index];
        [$x2, $y2] = $polygon[($index + 1) % $count];
        [$low, $high] = [min($y1, $y2), max($y1, $y2)];
        if ($low !== $high && ($closedBelow ? $low < $y && $y <= $high : $low <= $y && $y < $high)) {
          $crossings[] = $x1 + ($y - $y1) * ($x2 - $x1) / ($y2 - $y1);
        }
      }
      sort($crossings);
      for ($index = 0; $index + 1 < count($crossings); $index += 2) {
        $run = [$crossings[$index], $crossings[$index + 1]];
        if ($widest === null || $run[1] - $run[0] > $widest[1] - $widest[0]) { $widest = $run; }
      }
    }
    return $widest;
  }

  /**
   * The nearest canvas height to $y at which the polygon has footing,
   * searching outwards in whole units; $y itself when it has.
   *
   * @param list<array{0: float, 1: float}> $polygon
   */
  public static function getNearestFootedHeight(array $polygon, float $y): float
  {
    if ($polygon === [] || self::getSpan($polygon, $y) !== null) { return $y; }
    $heights = array_column($polygon, 1);
    for ($step = 1; $step <= (max($heights) - min($heights)) + abs($y - min($heights)) + 1; $step++) {
      foreach ([$y - $step, $y + $step] as $candidate) {
        if (self::getSpan($polygon, $candidate) !== null) { return $candidate; }
      }
    }
    return $y;
  }

  /** @return array{enemies?: list<array{x: float, y: float}>, party?: list<array{x: float, y: float}>} */
  public function toArray(): array
  {
    $points = static fn(array $polygon): array => array_map(static fn(array $point): array => ['x' => $point[0], 'y' => $point[1]], $polygon);
    return array_filter(['enemies' => $points($this->enemies), 'party' => $points($this->party)], static fn(array $polygon): bool => $polygon !== []);
  }

  /**
   * Reads one side's polygon as authored: a list of `['x' => x, 'y' => y]` canvas points.
   *
   * @return list<array{0: float, 1: float}>
   */
  public static function readPolygon(mixed $data, string $where): array
  {
    if (!is_array($data) || !array_is_list($data)) {
      throw new InvalidArgumentException("{$where}: expected a list of points, each with x and y.");
    }
    $polygon = [];
    foreach ($data as $index => $point) {
      if (!is_array($point) || array_diff(array_keys($point), ['x', 'y']) !== []
        || !is_int($point['x'] ?? null) && !is_float($point['x'] ?? null)
        || !is_int($point['y'] ?? null) && !is_float($point['y'] ?? null)) {
        throw new InvalidArgumentException("{$where}[{$index}]: expected a point with numeric x and y.");
      }
      $polygon[] = [(float) $point['x'], (float) $point['y']];
    }
    return $polygon;
  }

  /** @return list<array{0: float, 1: float}> */
  private static function getValidatedPolygon(array $polygon, string $side): array
  {
    if (!array_is_list($polygon) || ($polygon !== [] && count($polygon) < 3) || count($polygon) > 256) {
      throw new InvalidArgumentException("The {$side}' footing must be a polygon of 3 to 256 points, or none.");
    }
    $copy = [];
    foreach ($polygon as $point) {
      if (!is_array($point) || count($point) !== 2 || !array_is_list($point)) {
        throw new InvalidArgumentException("The {$side}' footing points must be [x, y] pairs.");
      }
      foreach ($point as $value) {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 16384) {
          throw new InvalidArgumentException("The {$side}' footing points must be finite canvas positions within 0..16384.");
        }
      }
      $copy[] = [(float) $point[0], (float) $point[1]];
    }
    return $copy;
  }
}
