<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Insight;

use Ichiloto\Engine\Entities\Elements\ElementRegistry;

/**
 * Which element strikes a battler hardest: the project's elements whose
 * damage multiplier against it is the highest and above ordinary (1.0).
 * When no element beats ordinary damage, there is none.
 *
 * @package Ichiloto\Engine\Battle\Insight
 */
final class ElementalInsight
{
  private function __construct() {}

  /**
   * @param object $battler A battler exposing `getElementMultiplier(?string): float`.
   * @return list<string> The strongest elements, in the project's element order; empty when none beats ordinary damage.
   */
  public static function findStrongestElements(object $battler): array
  {
    if (! method_exists($battler, 'getElementMultiplier')) {
      return [];
    }
    $best = 1.0;
    $elements = [];
    foreach (ElementRegistry::identities() as $element) {
      $multiplier = floatval($battler->getElementMultiplier($element));
      if ($multiplier > $best) {
        $best = $multiplier;
        $elements = [$element];
      } elseif ($multiplier === $best && $best > 1.0) {
        $elements[] = $element;
      }
    }

    return $elements;
  }
}
