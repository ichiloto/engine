<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Immutable Terminal-cell capture. Selection is combatant identity; graphical coordinates never enter it. */
final readonly class TerminalBattleLaneSnapshot
{
  /** @var array{x:int,y:int} */
  public array $caster;
  /** @var array<int,array{x:int,y:int}> */
  public array $targets;
  /** @var list<array{x:int,y:int,width:int,height:int}> */
  private array $obstacles;

  public function __construct(array $caster, array $targets, array $obstacles, private bool $casterPartySide)
  {
    $this->caster = self::copyPoint($caster);
    if ($targets === [] || count($targets) > 128) { throw new InvalidArgumentException('A Terminal lane needs selected identities.'); }
    $copy = [];
    foreach ($targets as $id => $point) {
      if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('Terminal lane identities require combatant instance IDs.'); }
      $copy[$id] = self::copyPoint($point);
    }
    $this->targets = $copy;
    if (!array_is_list($obstacles) || count($obstacles) > 256) { throw new InvalidArgumentException('Terminal lane obstacles exceed the battler bound.'); }
    $copy = [];
    foreach ($obstacles as $box) {
      $point = self::copyPoint(is_array($box) ? array_intersect_key($box, ['x' => true, 'y' => true]) : []);
      if (array_diff(array_keys($box), ['x','y','width','height']) !== []
        || !is_int($box['width'] ?? null) || !is_int($box['height'] ?? null)
        || min($box['width'], $box['height']) < 1 || max($box['width'], $box['height']) > 16384) {
        throw new InvalidArgumentException('Terminal lane obstacles require positive bounded cell extents.');
      }
      $copy[] = $point + ['width' => $box['width'], 'height' => $box['height']];
    }
    $this->obstacles = $copy;
  }

  private static function copyPoint(array $point): array
  {
    if (array_diff(array_keys($point), ['x', 'y']) !== [] || !is_int($point['x'] ?? null) || !is_int($point['y'] ?? null)
      || max(abs($point['x']), abs($point['y'])) > 16384) {
      throw new InvalidArgumentException('Terminal lane points require bounded integer cells.');
    }
    return ['x' => $point['x'], 'y' => $point['y']];
  }

  /** One head reaches the far selected column at the selected group's middle row. */
  public function getEndpoint(): array
  {
    $xs = array_column($this->targets, 'x'); $ys = array_column($this->targets, 'y');
    $sign = $this->getDirectionSign();
    return ['x' => $sign < 0 ? min($xs) : max($xs), 'y' => (int)round((min($ys) + max($ys)) / 2)];
  }

  public function getDirectionSign(): int
  {
    $xs = array_column($this->targets, 'x');
    $midpoint = (min($xs) + max($xs)) / 2;
    return ($midpoint <=> $this->caster['x']) === 0 ? BattleEffectDirection::getOpponentsSign($this->casterPartySide)
      : ($midpoint < $this->caster['x'] ? -1 : 1);
  }

  /** Transparent omission at unrelated bodies is occlusion, never target selection or a second hit. */
  public function isOccluded(int $x, int $y, int $width = 1): bool
  {
    return array_any($this->obstacles, static fn(array $box): bool => $y >= $box['y'] && $y < $box['y'] + $box['height']
      && $x < $box['x'] + $box['width'] && $x + $width > $box['x']);
  }
}
