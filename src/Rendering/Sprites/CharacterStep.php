<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/**
 * One committed field step as presentation sees it: the cell left, the cell
 * reached and how long the step takes to show. Zero seconds is an instant
 * step (reduced-motion routes, for example), shown without a slide.
 */
final readonly class CharacterStep
{
  public Vector2 $from;
  public Vector2 $to;

  public function __construct(Vector2 $from, Vector2 $to, public float $seconds)
  {
    if (!is_finite($seconds) || $seconds < 0.0) {
      throw new InvalidArgumentException('A step lasts a finite, nonnegative number of seconds.');
    }
    // Field positions are mutated in place; the step keeps its own copies.
    $this->from = clone $from;
    $this->to = clone $to;
  }

  public function getDisplacement(): Vector2
  {
    return new Vector2($this->to->x - $this->from->x, $this->to->y - $this->from->y);
  }
}
