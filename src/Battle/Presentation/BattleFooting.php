<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use InvalidArgumentException;

/**
 * Where on an arena's picture battlers may plant their feet: a polygon of
 * canvas points for its land (the dirt of a road, not its grass verge or
 * rocks) and one for its water. An arena with neither is land throughout.
 * The party stands on land; each enemy stands where its habitat lives
 * ({@see EnemyHabitat}), an air enemy over land or water. Each side still
 * keeps to its own area. Staging fits feet into it and spreads an arranged
 * troop along its shape ({@see BattleStaging}). Graphical presentation only;
 * the Terminal battle never reads it.
 */
final readonly class BattleFooting
{
  /** @var list<array{0: float, 1: float}> */
  public array $land;
  /** @var list<array{0: float, 1: float}> */
  public array $water;
  /** Whether the arena marks any footing; one that marks none is land throughout. */
  public bool $isDeclared;

  /**
   * @param list<array{0: float, 1: float}> $land The land's polygon, or none.
   * @param list<array{0: float, 1: float}> $water The water's polygon, or none.
   */
  public function __construct(array $land = [], array $water = [])
  {
    $this->land = self::getValidatedPolygon($land, 'land');
    $this->water = self::getValidatedPolygon($water, 'water');
    $this->isDeclared = $this->land !== [] || $this->water !== [];
  }


  /**
   * The polygons a battler of a habitat may stand in: land's or water's, an
   * air battler's either. None means no limit (an arena that marks no
   * footing) or, for a declared arena, nothing of that habitat, which
   * {@see hasFootingFor()} reports.
   *
   * @return list<list<array{0: float, 1: float}>>
   */
  public function getPolygons(EnemyHabitat $habitat): array
  {
    return array_values(array_filter(match ($habitat) {
      EnemyHabitat::LAND => [$this->land],
      EnemyHabitat::WATER => [$this->water],
      EnemyHabitat::AIR => [$this->land, $this->water],
    }, static fn(array $polygon): bool => $polygon !== []));
  }

  /**
   * Whether a battler of a habitat has somewhere to stand here: an arena
   * that marks no footing is land throughout, with no water.
   */
  public function hasFootingFor(EnemyHabitat $habitat): bool
  {
    return $this->isDeclared ? $this->getPolygons($habitat) !== [] : $habitat !== EnemyHabitat::WATER;
  }

  /**
   * The widest stretch a battler of a habitat may stand on across a canvas
   * height, at the nearest height it has footing; null where it has no limit.
   *
   * @return array{0: float, 1: float, 2: float}|null The run's left and right, and the footed height.
   */
  public function getFootedRun(EnemyHabitat $habitat, float $y): ?array
  {
    $widest = null;
    foreach ($this->getPolygons($habitat) as $polygon) {
      $height = self::getNearestFootedHeight($polygon, $y);
      $run = self::getSpan($polygon, $height);
      if ($run !== null && ($widest === null || abs($height - $y) < abs($widest[2] - $y)
          || ($height === $widest[2] && $run[1] - $run[0] > $widest[1] - $widest[0]))) {
        $widest = [$run[0], $run[1], $height];
      }
    }
    return $widest;
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

  /** @return array{land?: list<array{x: float, y: float}>, water?: list<array{x: float, y: float}>} */
  public function toArray(): array
  {
    $points = static fn(array $polygon): array => array_map(static fn(array $point): array => ['x' => $point[0], 'y' => $point[1]], $polygon);
    return array_filter(['land' => $points($this->land), 'water' => $points($this->water)], static fn(array $polygon): bool => $polygon !== []);
  }

  /**
   * Reads an arena's footing as authored: `['land' => points, 'water' => points]`,
   * each a list of `['x' => x, 'y' => y]` canvas points.
   */
  public static function fromArray(mixed $data, string $where): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['land', 'water']) !== []) {
      throw new InvalidArgumentException("{$where}: expected land and water polygons only.");
    }
    try {
      return new self(array_key_exists('land', $data) ? self::readPolygon($data['land'], "{$where}.land") : [],
        array_key_exists('water', $data) ? self::readPolygon($data['water'], "{$where}.water") : []);
    } catch (InvalidArgumentException $error) {
      throw str_starts_with($error->getMessage(), $where) ? $error : new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error);
    }
  }

  /**
   * Reads one polygon as authored: a list of `['x' => x, 'y' => y]` canvas points.
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
  private static function getValidatedPolygon(array $polygon, string $habitat): array
  {
    if (!array_is_list($polygon) || ($polygon !== [] && count($polygon) < 3) || count($polygon) > 256) {
      throw new InvalidArgumentException("The {$habitat} footing must be a polygon of 3 to 256 points, or none.");
    }
    $copy = [];
    foreach ($polygon as $point) {
      if (!is_array($point) || count($point) !== 2 || !array_is_list($point)) {
        throw new InvalidArgumentException("The {$habitat} footing points must be [x, y] pairs.");
      }
      foreach ($point as $value) {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 16384) {
          throw new InvalidArgumentException("The {$habitat} footing points must be finite canvas positions within 0..16384.");
        }
      }
      $copy[] = [(float) $point[0], (float) $point[1]];
    }
    return $copy;
  }
}
