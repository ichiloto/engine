<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * How the Engine spaces a side it arranges ({@see BattleStaging::arrange()}):
 * battlers stand in a staggered echelon from back to front, each rank `depth`
 * times the nearer battler's drawn height behind the one in front. Ranks
 * alternate between an inner lane (the front rank's, towards the middle of
 * the field) and an outer lane `lateral` times their mean drawn width beyond
 * it. A theme value; spacing follows the current artwork's size.
 */
final readonly class BattleFormationSpacing
{
  public function __construct(
    public float $depth = 0.45,
    public float $lateral = 1.1,
  ) {
    foreach ([$depth, $lateral] as $factor) {
      if (!is_finite($factor) || $factor < 0 || $factor > 4) {
        throw new InvalidArgumentException('Formation spacing factors must be finite within 0..4.');
      }
    }
  }
}
