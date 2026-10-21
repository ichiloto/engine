<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use InvalidArgumentException;

/**
 * Where every battler of one graphical battle stands: the party's and the
 * troop's formations stood on the arena's ground. A formation is authored once
 * as stage slots (feet column and depth back from the ground's near edge),
 * so the same troop or party stands on any arena's ground: nobody in front of
 * the near edge, nobody beyond the far edge. An arranged troop centres on the
 * middle of the theme's party formation so the sides face each other. A side deeper than the
 * ground is compressed in depth as a whole, keeping its shape, and the
 * ground's perspective scales battlers by how far back they stand. Feet land
 * on the footing their habitat lives on when the arena marks one ({@see BattleFooting}).
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
   * @param list<EnemyHabitat> $enemyHabitats Where each troop member lives; none stands every member on land.
   */
  public static function stage(BattleCanvasLayout $layout, ?BattleArenaDefinition $arena, array $partyBodies,
    array $enemyPlaces, array $enemyBodies, array $enemyHabitats = []): self
  {
    if ($enemyHabitats === []) { $enemyHabitats = array_fill(0, count($enemyBodies), EnemyHabitat::LAND); }
    if (!array_is_list($partyBodies) || !array_is_list($enemyPlaces) || !array_is_list($enemyBodies)
      || count($enemyPlaces) !== count($enemyBodies) || count($partyBodies) > 64 || count($enemyBodies) > 64
      || !array_is_list($enemyHabitats) || count($enemyHabitats) !== count($enemyBodies)
      || array_any($enemyHabitats, static fn(mixed $habitat): bool => !$habitat instanceof EnemyHabitat)) {
      throw new InvalidArgumentException('Staging requires one drawn size per battler and one place or null and one habitat per troop member.');
    }
    $ground = $layout->getGround($arena);
    $footing = $arena?->footing ?? new BattleFooting();
    $party = self::getPartyPlaces($layout, $partyBodies);
    $partyCompression = self::getCompression($ground, $party);
    $enemies = self::arrange($layout, false, $enemyBodies, $enemyPlaces, $ground, $footing, $enemyHabitats);
    $enemyCompression = self::getCompression($ground, $enemies);
    // The party stands on land; each troop member where its habitat lives.
    $partySlots = array_map(static fn(BattleStageSlot $place): BattlerSlot => self::getFootedSlot($layout, true, $ground, $footing,
      EnemyHabitat::LAND, $place->getSlotOn($ground, $partyCompression)), $party);
    $enemySlots = array_map(static fn(BattleStageSlot $place, EnemyHabitat $habitat): BattlerSlot => self::getFootedSlot($layout, false,
      $ground, $footing, $habitat, $place->getSlotOn($ground, $enemyCompression)), $enemies, $enemyHabitats);
    return new self($ground, $partySlots, $enemySlots, $party, $enemies, $partyCompression, $enemyCompression);
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
   * battlers' drawn sizes. A troop centres its depth on the middle of the
   * theme's party formation, so the sides face each other across the field
   * and a lone enemy stands mid-field, and a troop aligned in an editor
   * stands where the battle would arrange it whatever the party's size.
   * Without a theme formation both sides start from the near edge alike.
   * Across, each rank centres on the footing its habitat lives on at the
   * height it stands at on the given ground (the side's area without
   * footing), so the formation follows a road rather than a verge: feet stay
   * on the footing, art on the side. Authored places are
   * kept; each unplaced member takes the arrangement's spot that no
   * authored member stands nearest, in member order.
   *
   * @param list<array{width: float, height: float, pivotX?: float, pivotY?: float}> $bodies Drawn size and the feet's shares of it.
   * @param list<?BattleStageSlot> $placed
   * @param ?BattleFooting $footing The arena's footing on that ground; none stands anywhere on the side's area.
   * @param list<EnemyHabitat> $habitats Where each battler lives; none stands every one on land.
   * @return list<BattleStageSlot>
   */
  public static function arrange(BattleCanvasLayout $layout, bool $party, array $bodies, array $placed = [],
    ?BattleGroundBand $ground = null, ?BattleFooting $footing = null, array $habitats = []): array
  {
    $count = count($bodies);
    if ($placed === []) { $placed = array_fill(0, $count, null); }
    if ($habitats === []) { $habitats = array_fill(0, $count, EnemyHabitat::LAND); }
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
    $centreDepth = $party || $layout->partySlots === [] ? null
      : array_sum(array_map(static fn(BattleStageSlot $slot): float => $slot->depth, $layout->partySlots)) / count($layout->partySlots);
    $spots = self::getStagger($layout, $party, $bodies, $centreDepth, $ground ?? $layout->getGround(null), $footing ?? new BattleFooting(),
      $habitats);
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

  /**
   * @param list<array{width: float, height: float, pivotX?: float, pivotY?: float}> $bodies
   * @param list<EnemyHabitat> $habitats
   * @return list<BattleStageSlot>
   */
  private static function getStagger(BattleCanvasLayout $layout, bool $party, array $bodies, ?float $centreDepth,
    BattleGroundBand $ground, BattleFooting $footing, array $habitats): array
  {
    $count = count($bodies);
    $outward = $party ? 1 : -1;
    // Ranks alternate lanes from the front: the front rank on the inner lane, the next on the outer, and so on.
    $offsets = array_map(static fn(int $index): float => $outward * (($count - 1 - $index) % 2), range(0, $count - 1));
    // Art drawn below the feet (a shadow, a creature's body under its waterline) stays above the battler area's
    // bottom, or the battle UI's top without one: a battler that would reach below stands that much further back.
    $floor = $layout->battlerArea === null ? $layout->getHudTop() : $layout->battlerArea->y + $layout->battlerArea->height;
    $lifts = array_map(static fn(array $body): float => max(0.0, $ground->bottom + (1 - ($body['pivotY'] ?? 1.0)) * $body['height'] - $floor),
      $bodies);
    $depths = array_fill(0, $count, 0.0);
    $depths[$count - 1] = $lifts[$count - 1];
    for ($index = $count - 2; $index >= 0; $index--) {
      $depths[$index] = max($lifts[$index], $depths[$index + 1] + $layout->spacing->depth * $bodies[$index + 1]['height']);
    }
    if ($centreDepth !== null) {
      $shift = $centreDepth - ($depths[0] + $depths[$count - 1]) / 2;
      $depths = array_map(static fn(float $depth): float => $depth + $shift, $depths);
      // Never in front of the near edge, nor so far forward the art reaches below the battler area.
      $deficit = max(0.0, ...array_map(static fn(float $depth, float $lift): float => $lift - $depth, $depths, $lifts));
      $depths = array_map(static fn(float $depth): float => $depth + $deficit, $depths);
    }
    [$left, $right] = self::getSideRange($layout, $party);
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
    // The formation never leaves the side: narrow the lanes until it fits.
    for ($narrowing = 0; $narrowing < 32 && ($getExtent($step)[1] - $getExtent($step)[0]) > $right - $left; $narrowing++) {
      $step *= .85;
    }
    [$near, $far] = $getExtent($step);
    // Each rank centres across the footing at the height its feet land at on this ground (across the side without
    // footing), so a formation follows a road's line; its art stays on the side and its feet on the footing.
    $compression = max($depths) <= $ground->depth ? 1.0 : $ground->depth / max($depths);
    $spots = [];
    foreach ($bodies as $index => $body) {
      [$footLeft, $footRight] = self::getStandingSpan($layout, $party, $ground->getFootY($depths[$index] * $compression), $footing,
        $habitats[$index] ?? EnemyHabitat::LAND);
      $x = ($footLeft + $footRight) / 2 - ($near + $far) / 2 + $offsets[$index] * $step;
      $share = $body['pivotX'] ?? .5;
      if ($right - $left >= $body['width']) {
        $x = max($left + $share * $body['width'], min($right - (1 - $share) * $body['width'], $x));
      }
      $x = max($footLeft, min($footRight, $x));
      $spots[] = new BattleStageSlot(max(0, min($layout->width, $x)), $depths[$index], $body['width'], $body['height']);
    }
    return $spots;
  }

  /**
   * The canvas columns a side's art stands within: its authored area, or
   * without one, the left half for enemies and the right for the party.
   *
   * @return array{0: float, 1: float}
   */
  private static function getSideRange(BattleCanvasLayout $layout, bool $party): array
  {
    $area = $party ? $layout->partyArea : $layout->enemyArea;
    return $area === null ? ($party ? [$layout->width / 2, $layout->width] : [0.0, $layout->width / 2])
      : [$area->x, $area->x + $area->width];
  }

  /**
   * The run of canvas columns a battler of a habitat may stand on at a
   * height: its footing there (at the nearest footed height when it does
   * not reach), within the side's area when they overlap; the side's area
   * alone where its habitat has no footing to keep to.
   *
   * @return array{0: float, 1: float}
   */
  private static function getStandingSpan(BattleCanvasLayout $layout, bool $party, float $footY, BattleFooting $footing,
    EnemyHabitat $habitat): array
  {
    $side = self::getSideRange($layout, $party);
    $run = $footing->getFootedRun($habitat, $footY, $side);
    if ($run === null) { return $side; }
    $overlap = [max($side[0], $run[0]), min($side[1], $run[1])];
    return $overlap[0] < $overlap[1] ? $overlap : $run;
  }

  /**
   * A staged slot moved onto the footing its habitat lives on when its feet
   * fall outside it: to the nearest footed height, then across into the
   * footing there, its perspective scale following the new height. A slot
   * already on its footing, or one with no footing to keep to, is returned
   * as it is.
   */
  private static function getFootedSlot(BattleCanvasLayout $layout, bool $party, BattleGroundBand $ground, BattleFooting $footing,
    EnemyHabitat $habitat, BattlerSlot $slot): BattlerSlot
  {
    $run = $footing->getFootedRun($habitat, $slot->y, self::getSideRange($layout, $party));
    if ($run === null) { return $slot; }
    $y = max($ground->top, min($ground->bottom, $run[2]));
    [$left, $right] = self::getStandingSpan($layout, $party, $y, $footing, $habitat);
    $x = max($left, min($right, $slot->x));
    if ($x === $slot->x && $y === $slot->y) { return $slot; }
    $scale = $slot->displayScale / $ground->getDepthScale($slot->y) * $ground->getDepthScale($y);
    return new BattlerSlot($x, $y, $slot->width, $slot->height, $scale);
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
