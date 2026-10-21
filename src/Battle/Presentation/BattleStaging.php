<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * Where every battler of one graphical battle stands: the party's and the
 * troop's formations stood on the arena's ground. A formation is authored once
 * as stage slots (feet column and depth back from the ground's near edge),
 * so the same troop or party stands on any arena's ground: the front rank on
 * the near edge, nobody beyond the far edge. A side deeper than the ground is
 * compressed in depth as a whole, keeping its shape, and the ground's
 * perspective scales battlers by how far back they stand.
 *
 * A party smaller than the theme's formation takes its front-most slots. A
 * member with no authored place (a troop placed by nobody yet, or a member
 * added since) is arranged by the Engine's rule beside those that have one.
 * Presentation only: combat, the Terminal battle and saves never read it, and
 * richer staging (moving poses, effects, summons) builds on the same slots.
 */
final readonly class BattleStaging
{
  /**
   * @param list<BattlerSlot> $party Frontline order.
   * @param list<BattlerSlot> $enemies Troop member order.
   * @param list<BattleStageSlot> $partyPlaces
   * @param list<BattleStageSlot> $enemyPlaces
   */
  private function __construct(
    public BattleGroundBand $ground,
    public array $party,
    public array $enemies,
    public array $partyPlaces,
    public array $enemyPlaces,
    public float $partyCompression,
    public float $enemyCompression,
  ) {}

  /**
   * @param list<array{width: float, height: float}> $partyBodies Each frontline member's drawn size before slot depth.
   * @param list<?BattleStageSlot> $enemyPlaces Each troop member's authored place, or null to arrange it.
   * @param list<array{width: float, height: float}> $enemyBodies Each troop member's drawn size before slot depth.
   */
  public static function stage(BattleCanvasLayout $layout, ?BattleArenaDefinition $arena, array $partyBodies,
    array $enemyPlaces, array $enemyBodies): self
  {
    if (!array_is_list($partyBodies) || !array_is_list($enemyPlaces) || !array_is_list($enemyBodies)
      || count($enemyPlaces) !== count($enemyBodies) || count($partyBodies) > 64 || count($enemyBodies) > 64) {
      throw new InvalidArgumentException('Staging requires one drawn size per battler and one place or null per troop member.');
    }
    $ground = $layout->getGround($arena);
    $party = self::getPartyPlaces($layout, $partyBodies);
    $enemies = self::arrange($layout, false, $enemyBodies, $enemyPlaces);
    $partyCompression = self::getCompression($ground, $party);
    $enemyCompression = self::getCompression($ground, $enemies);
    return new self($ground,
      array_map(static fn(BattleStageSlot $place): BattlerSlot => $place->getSlotOn($ground, $partyCompression), $party),
      array_map(static fn(BattleStageSlot $place): BattlerSlot => $place->getSlotOn($ground, $enemyCompression), $enemies),
      $party, $enemies, $partyCompression, $enemyCompression);
  }

  /**
   * A frontline's places: the theme formation's front-most slots, or the
   * Engine's arrangement when the theme authors none.
   *
   * @param list<array{width: float, height: float}> $bodies
   * @return list<BattleStageSlot>
   */
  public static function getPartyPlaces(BattleCanvasLayout $layout, array $bodies): array
  {
    if ($layout->partySlots === []) { return self::arrange($layout, true, $bodies); }
    if (count($bodies) > count($layout->partySlots)) {
      throw new InvalidArgumentException('The party formation requires a slot for every frontline member.');
    }
    return array_slice($layout->partySlots, count($layout->partySlots) - count($bodies));
  }

  /**
   * The Engine's arrangement of one side: a staggered echelon from back
   * (first) to front (last), each rank stepping back by a share of the
   * nearer battler's height and alternating between an inner and an outer
   * lane, spaced by the theme ({@see BattleFormationSpacing}) and the
   * battlers' drawn sizes, centred across the side's area. Authored
   * places are kept; each unplaced member takes the arrangement's spot that
   * no authored member stands nearest, in member order.
   *
   * @param list<array{width: float, height: float, pivotX?: float, pivotY?: float}> $bodies Drawn size and the feet's shares of it.
   * @param list<?BattleStageSlot> $placed
   * @return list<BattleStageSlot>
   */
  public static function arrange(BattleCanvasLayout $layout, bool $party, array $bodies, array $placed = []): array
  {
    $count = count($bodies);
    if ($placed === []) { $placed = array_fill(0, $count, null); }
    if (!array_is_list($bodies) || !array_is_list($placed) || count($placed) !== $count) {
      throw new InvalidArgumentException('Arranging a side requires one drawn size and one place or null per battler.');
    }
    if (!in_array(null, $placed, true)) { return $placed; }
    foreach ($bodies as $body) {
      if (!is_array($body) || !self::isSize($body['width'] ?? null) || !self::isSize($body['height'] ?? null)
        || array_any(['pivotX', 'pivotY'], static fn(string $axis): bool => isset($body[$axis])
          && (!is_float($body[$axis]) || $body[$axis] < 0 || $body[$axis] > 1))) {
        throw new InvalidArgumentException('Battler drawn sizes must be finite and positive, with pivot shares within 0..1.');
      }
    }
    $spots = self::getStagger($layout, $party, $bodies);
    $free = array_keys($spots);
    foreach ($placed as $place) {
      if ($place === null) { continue; }
      usort($free, static fn(int $one, int $two): int => self::getDistance($spots[$one], $place) <=> self::getDistance($spots[$two], $place));
      array_shift($free);
    }
    sort($free);
    $arranged = [];
    foreach ($placed as $index => $place) {
      $spot = $place === null ? $spots[array_shift($free)] : null;
      $arranged[] = $place ?? new BattleStageSlot($spot->x, $spot->depth,
        $bodies[$index]['width'], $bodies[$index]['height']);
    }
    return $arranged;
  }

  /** @param list<array{width: float, height: float, pivotX?: float, pivotY?: float}> $bodies @return list<BattleStageSlot> */
  private static function getStagger(BattleCanvasLayout $layout, bool $party, array $bodies): array
  {
    $count = count($bodies);
    $area = $party ? $layout->partyArea : $layout->enemyArea;
    // Without an authored side area, enemies take the left half and the party the right.
    $left = $area?->x ?? ($party ? $layout->width / 2 : 0);
    $right = $area === null ? ($party ? $layout->width : $layout->width / 2) : $area->x + $area->width;
    $outward = $party ? 1 : -1;
    // Ranks alternate lanes from the front: the front rank on the inner lane, the next on the outer, and so on.
    $offsets = array_map(static fn(int $index): float => $outward * (($count - 1 - $index) % 2), range(0, $count - 1));
    // Each battler's art reaches its pivot's share of its width to the left of its feet, the rest to the right.
    $getExtent = static function (float $step) use ($bodies, $offsets): array {
      $reach = [INF, -INF];
      foreach ($bodies as $index => $body) {
        $share = $body['pivotX'] ?? .5;
        $reach = [min($reach[0], $offsets[$index] * $step - $share * $body['width']),
          max($reach[1], $offsets[$index] * $step + (1 - $share) * $body['width'])];
      }
      return $reach;
    };
    $step = $layout->spacing->lateral * array_sum(array_column($bodies, 'width')) / $count;
    // The formation never leaves the side: narrow the lanes until it fits, then centre it.
    for ($narrowing = 0; $narrowing < 32 && ($getExtent($step)[1] - $getExtent($step)[0]) > $right - $left; $narrowing++) {
      $step *= .85;
    }
    [$near, $far] = $getExtent($step);
    $centre = ($left + $right) / 2 - ($near + $far) / 2;
    // Art drawn below the feet (a shadow, a creature's body under its waterline) stays above the battler area's
    // bottom, or the battle UI's top without one: a battler that would reach below stands that much further back.
    $floor = $layout->battlerArea === null ? $layout->getHudTop() : $layout->battlerArea->y + $layout->battlerArea->height;
    $nearEdge = $layout->getGround(null)->bottom;
    $lifts = array_map(static fn(array $body): float => max(0.0, $nearEdge + (1 - ($body['pivotY'] ?? 1.0)) * $body['height'] - $floor),
      $bodies);
    $depths = array_fill(0, $count, 0.0);
    $depths[$count - 1] = $lifts[$count - 1];
    for ($index = $count - 2; $index >= 0; $index--) {
      $depths[$index] = max($lifts[$index], $depths[$index + 1] + $layout->spacing->depth * $bodies[$index + 1]['height']);
    }
    $spots = [];
    foreach ($bodies as $index => $body) {
      $x = $centre + $offsets[$index] * $step;
      $spots[] = new BattleStageSlot(max(0, min($layout->width, $x)), $depths[$index], $body['width'], $body['height']);
    }
    return $spots;
  }

  /** @param list<BattleStageSlot> $places */
  private static function getCompression(BattleGroundBand $ground, array $places): float
  {
    $deepest = max([0, ...array_map(static fn(BattleStageSlot $place): float => $place->depth, $places)]);
    return $deepest <= $ground->depth ? 1.0 : $ground->depth / $deepest;
  }

  private static function getDistance(BattleStageSlot $one, BattleStageSlot $two): float
  {
    return hypot($one->x - $two->x, $one->depth - $two->depth);
  }

  private static function isSize(mixed $value): bool
  {
    return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0 && $value <= 16384;
  }
}
