<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;

/** The selected recipients as drawn now, independent of damage and target selection. */
final readonly class BattleEffectTargetGroup
{
  /** @param array<int, Vector2> $grounds Stable combatant instance identity to current ground point. */
  private function __construct(
    public array $grounds,
    public CanvasRectangle $bounds,
    public float $left,
    public float $right,
    public float $far,
    public float $near,
    public float $spanWidth,
    public ?bool $partySide,
  ) {}

  /**
   * Collect the exact selected identities once. Keep different depths and
   * off-centre ground pivots; never substitute the first target or a screen row.
   * @param list<CharacterInterface> $targets
   * @param array<int, CanvasRectangle> $bounds Current visible bodies, or the host's name-only bounds.
   * @param array<int, Vector2> $grounds Current projected ground anchors.
   * @param array<int, bool> $partySides
   */
  public static function collectTargets(array $targets, array $bounds, array $grounds, array $partySides = []): ?self
  {
    if ($targets === []) { return null; }
    $selected = [];
    $left = $top = $groundLeft = $far = INF;
    $right = $bottom = $groundRight = $near = -INF;
    $halfSpan = 0.0;
    $sides = [];
    foreach ($targets as $target) {
      $id = spl_object_id($target);
      if (isset($selected[$id])) { continue; }
      $box = $bounds[$id] ?? throw new RuntimeException('Target-group effect requires every selected body or name anchor.');
      $point = $grounds[$id] ?? throw new RuntimeException('Target-group effect requires every selected ground point.');
      $selected[$id] = clone $point;
      $left = min($left, $box->x); $top = min($top, $box->y);
      $right = max($right, $box->x + $box->width); $bottom = max($bottom, $box->y + $box->height);
      $groundLeft = min($groundLeft, $point->x); $groundRight = max($groundRight, $point->x);
      $far = min($far, $point->y); $near = max($near, $point->y);
      // Symmetric reach around the real feet covers an off-centre body at
      // either end without moving the group's ground origin toward its artwork.
      $halfSpan = max($halfSpan, abs($point->x - $box->x), abs($box->x + $box->width - $point->x));
      $sides[] = $partySides[$id] ?? null;
    }
    $side = $sides[0];
    if (array_any($sides, static fn(?bool $candidate): bool => $candidate !== $side)) { $side = null; }
    return new self($selected, new CanvasRectangle($left, $top, $right - $left, $bottom - $top),
      $groundLeft, $groundRight, $far, $near, $groundRight - $groundLeft + 2 * $halfSpan, $side);
  }

  /** Centre across the actual ground points, at their nearest depth. */
  public function getGroundOrigin(): Vector2
  {
    return new Vector2(($this->left + $this->right) / 2, $this->near);
  }
}
