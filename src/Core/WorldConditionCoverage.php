<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Progression\WorldAbilityCatalog;

/** Proves that guarded alternatives cover every state allowed by the owner's presence conditions. */
final class WorldConditionCoverage
{
  private function __construct() {}

  /**
   * Uses the shared complement proof, including its condition defaults and world-ability expansion.
   * Relationships the evaluator cannot prove leave coverage unverified; no world state is invented or changed.
   * @param list<array<string,mixed>> $presence
   * @param list<list<array<string,mixed>>> $alternatives
   */
  public static function proveCovered(array $presence, array $alternatives, ?WorldAbilityCatalog $worldAbilities = null): bool
  {
    WorldConditionEvaluator::validateAll($presence, 'Content owner presence');
    foreach ($alternatives as $conditions) { WorldConditionEvaluator::validateAll($conditions, 'Content alternative'); }
    if (array_any($alternatives, static fn(array $conditions): bool => $conditions === [])) { return true; }
    $memo = [];
    return self::proveRemainder($presence, $alternatives, 0, $worldAbilities, $memo);
  }

  /** Each alternative is a conjunction; its uncovered states require at least one complemented condition. */
  private static function proveRemainder(array $presence, array $alternatives, int $index,
    ?WorldAbilityCatalog $worldAbilities, array &$memo): bool
  {
    if (WorldConditionExclusion::proveExclusive($presence, $presence, $worldAbilities)) { return true; }
    if (!isset($alternatives[$index])) { return false; }
    $keys = [];
    foreach ($presence as $condition) {
      ksort($condition);
      $keys[] = json_encode($condition, JSON_THROW_ON_ERROR);
    }
    sort($keys);
    $key = json_encode([$index, array_values(array_unique($keys))], JSON_THROW_ON_ERROR);
    if (array_key_exists($key, $memo)) { return $memo[$key]; }
    foreach ($alternatives[$index] as $condition) {
      $negated = [];
      foreach ($condition as $field => $value) { $negated[$field] = $value; }
      $negated['negate'] = !($condition['negate'] ?? false);
      if (!self::proveRemainder([...$presence, $negated], $alternatives, $index + 1, $worldAbilities, $memo)) {
        return $memo[$key] = false;
      }
    }
    return $memo[$key] = true;
  }
}
