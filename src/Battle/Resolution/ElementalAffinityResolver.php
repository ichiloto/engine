<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Bounded ordinary affinity composition with explicit exceptional precedence. */
final class ElementalAffinityResolver
{
  /** @param float[] $factors @return array{outcome: ElementalOutcome, multiplier: float} */
  public static function compose(array $factors): array
  {
    if (in_array(0.0, $factors, true)) {
      return ['outcome' => ElementalOutcome::NULL, 'multiplier' => 0.0];
    }

    if (array_any($factors, static fn(float $factor): bool => $factor < 0.0)) {
      $magnitude = array_product(array_map(abs(...), $factors));
      return [
        'outcome' => ElementalOutcome::ABSORB,
        'multiplier' => -clamp($magnitude, CombatCalibration::ORDINARY_WARD_FLOOR, CombatCalibration::ORDINARY_WEAKNESS_CEILING),
      ];
    }

    $multiplier = clamp(
      array_product($factors),
      CombatCalibration::ORDINARY_WARD_FLOOR,
      CombatCalibration::ORDINARY_WEAKNESS_CEILING,
    );

    return [
      'outcome' => match (true) {
        $multiplier < 1.0 => ElementalOutcome::RESIST,
        $multiplier > 1.0 => ElementalOutcome::WEAK,
        default => ElementalOutcome::NORMAL,
      },
      'multiplier' => $multiplier,
    ];
  }

  /** @return array{outcome: ElementalOutcome, multiplier: float} */
  public static function forTarget(object $target, ?string $element): array
  {
    if ($element === null || trim($element) === '' || ! method_exists($target, 'getElementMultiplier')) {
      return ['outcome' => ElementalOutcome::NORMAL, 'multiplier' => 1.0];
    }

    $multiplier = floatval($target->getElementMultiplier($element));

    return [
      'outcome' => match (true) {
        $multiplier < 0.0 => ElementalOutcome::ABSORB,
        $multiplier === 0.0 => ElementalOutcome::NULL,
        $multiplier < 1.0 => ElementalOutcome::RESIST,
        $multiplier > 1.0 => ElementalOutcome::WEAK,
        default => ElementalOutcome::NORMAL,
      },
      'multiplier' => $multiplier,
    ];
  }
}
