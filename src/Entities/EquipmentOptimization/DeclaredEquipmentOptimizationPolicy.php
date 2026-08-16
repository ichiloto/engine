<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Stats\StatKey;
use InvalidArgumentException;

/**
 * Generic weighted optimization policy.
 *
 * Weight precedence is base, role, semantic slot, then role+slot. Accuracy
 * and critical are canonical pseudo-stat keys beside StatKey values.
 * Element and typed-special outcomes are opt-in fixed components.
 */
final class DeclaredEquipmentOptimizationPolicy implements EquipmentOptimizationPolicyInterface
{
  /** @var array<string, int> */
  private array $statWeights;
  /** @var array<string, array<string, int>> */
  private array $roleStatWeights;
  /** @var array<string, array<string, int>> */
  private array $slotStatWeights;
  /** @var array<string, array<string, array<string, int>>> */
  private array $roleSlotStatWeights;
  /** @var array<string, int> */
  private array $elementOutcomeWeights;
  /** @var array<string, int> */
  private array $specialPropertyWeights;
  /** @var array<string, true> */
  private array $excludedDefinitionIds;
  /** @var array<string, true> */
  private array $excludedAvailabilities;
  /** @var array<string, true> */
  private array $excludedAcquisitionPolicies;

  /**
   * @param array<string, int> $statWeights
   * @param array<string, array<string, int>> $roleStatWeights
   * @param array<string, array<string, int>> $slotStatWeights
   * @param array<string, array<string, array<string, int>>> $roleSlotStatWeights
   * @param array<string, int> $elementOutcomeWeights
   * @param array<string, int> $specialPropertyWeights
   * @param string[] $excludedDefinitionIds
   * @param string[] $excludedAvailabilities
   * @param string[] $excludedAcquisitionPolicies
   */
  public function __construct(
    array $statWeights = [],
    array $roleStatWeights = [],
    array $slotStatWeights = [],
    array $roleSlotStatWeights = [],
    array $elementOutcomeWeights = [],
    array $specialPropertyWeights = [],
    array $excludedDefinitionIds = [],
    array $excludedAvailabilities = [],
    array $excludedAcquisitionPolicies = [],
  )
  {
    $this->statWeights = self::normalizeWeights($statWeights, 'base');
    $this->roleStatWeights = self::normalizeScopedWeights($roleStatWeights, 'role');
    $this->slotStatWeights = self::normalizeScopedWeights($slotStatWeights, 'slot');
    $this->roleSlotStatWeights = [];

    foreach ($roleSlotStatWeights as $role => $slots) {
      if (! is_array($slots)) {
        throw new InvalidArgumentException('Role-slot equipment optimization weights must be arrays.');
      }

      $this->roleSlotStatWeights[self::normalize($role)] = self::normalizeScopedWeights(
        $slots,
        sprintf('role %s slot', $role),
      );
    }

    $this->elementOutcomeWeights = self::normalizeNamedWeights($elementOutcomeWeights, 'element outcome');
    $this->specialPropertyWeights = self::normalizeNamedWeights($specialPropertyWeights, 'special property');
    $this->excludedDefinitionIds = self::normalizeSet($excludedDefinitionIds);
    $this->excludedAvailabilities = self::normalizeSet($excludedAvailabilities);
    $this->excludedAcquisitionPolicies = self::normalizeSet($excludedAcquisitionPolicies);
  }

  public function score(
    Character $character,
    EquipmentSlot $slot,
    Equipment $equipment,
  ): ?EquipmentOptimizationScore
  {
    if ($this->isExcluded($equipment)) {
      return null;
    }

    $role = self::normalize($character->role->name);
    $semanticSlot = self::normalize($slot->semanticSlot->value);
    $weights = $this->statWeights;
    $weights = array_replace($weights, $this->roleStatWeights[$role] ?? []);
    $weights = array_replace($weights, $this->slotStatWeights[$semanticSlot] ?? []);
    $weights = array_replace($weights, $this->roleSlotStatWeights[$role][$semanticSlot] ?? []);
    $values = $equipment->parameterChanges->jsonSerialize();
    $values['accuracy'] = $equipment->accuracyModifier;
    $values['critical'] = $equipment->criticalModifier;
    $components = [];

    foreach ($weights as $stat => $weight) {
      $contribution = intval($values[$stat] ?? 0) * $weight;

      if ($contribution !== 0) {
        $components[$stat] = $contribution;
      }
    }

    if ($equipment->element !== null) {
      $this->addNamedComponent(
        $components,
        sprintf('offence:%s', self::normalize($equipment->element)),
        $this->elementOutcomeWeights,
      );
    }

    foreach ($equipment->elementAffinities as $element => $multiplier) {
      $outcome = match (true) {
        $multiplier < 0.0 => 'absorb',
        $multiplier === 0.0 => 'null',
        $multiplier < 1.0 => 'resist',
        $multiplier > 1.0 => 'weak',
        default => 'neutral',
      };
      $this->addNamedComponent(
        $components,
        sprintf('defence:%s:%s', self::normalize(strval($element)), $outcome),
        $this->elementOutcomeWeights,
      );
      $this->addNamedComponent(
        $components,
        sprintf('defence:*:%s', $outcome),
        $this->elementOutcomeWeights,
      );
    }

    $specialType = is_array($equipment->specialProperty)
      ? self::normalize(strval($equipment->specialProperty['type'] ?? ''))
      : '';

    if ($specialType !== '') {
      $this->addNamedComponent($components, $specialType, $this->specialPropertyWeights, 'special:');
    }

    return new EquipmentOptimizationScore(array_sum($components), $components);
  }

  /** @param array<string, int> $components @param array<string, int> $weights */
  private function addNamedComponent(
    array &$components,
    string $key,
    array $weights,
    string $componentPrefix = 'element:',
  ): void
  {
    $weight = $weights[self::normalize($key)] ?? 0;

    if ($weight !== 0) {
      $components[$componentPrefix . self::normalize($key)] = $weight;
    }
  }

  private function isExcluded(Equipment $equipment): bool
  {
    return isset($this->excludedDefinitionIds[self::normalize($equipment->id)])
      || isset($this->excludedAvailabilities[self::normalize($equipment->availability)])
      || ($equipment->acquisitionPolicy !== null
        && isset($this->excludedAcquisitionPolicies[self::normalize($equipment->acquisitionPolicy)]));
  }

  /** @param array<string, int> $weights @return array<string, int> */
  private static function normalizeWeights(array $weights, string $scope): array
  {
    $normalized = [];

    foreach ($weights as $key => $weight) {
      $lookup = self::normalize($key);
      $stat = array_find(
        StatKey::cases(),
        static fn(StatKey $candidate): bool => strtolower($candidate->value) === $lookup,
      );
      $canonical = $stat?->value ?? (in_array($lookup, ['accuracy', 'critical'], true) ? $lookup : null);

      if ($canonical === null) {
        throw new InvalidArgumentException(sprintf('Unknown %s equipment optimization key: %s.', $scope, $key));
      }

      if (! is_int($weight)) {
        throw new InvalidArgumentException(sprintf('%s equipment optimization weights require integer values.', ucfirst($scope)));
      }

      $normalized[$canonical] = $weight;
    }

    return $normalized;
  }

  /** @param array<string, array<string, int>> $scopes @return array<string, array<string, int>> */
  private static function normalizeScopedWeights(array $scopes, string $scopeType): array
  {
    $normalized = [];

    foreach ($scopes as $scope => $weights) {
      if (! is_array($weights)) {
        throw new InvalidArgumentException(sprintf('%s equipment optimization weights must be arrays.', ucfirst($scopeType)));
      }

      $normalized[self::normalize($scope)] = self::normalizeWeights($weights, sprintf('%s %s', $scopeType, $scope));
    }

    return $normalized;
  }

  /** @param array<string, int> $weights @return array<string, int> */
  private static function normalizeNamedWeights(array $weights, string $kind): array
  {
    $normalized = [];

    foreach ($weights as $name => $weight) {
      $name = self::normalize($name);

      if ($name === '' || ! is_int($weight)) {
        throw new InvalidArgumentException(sprintf('%s equipment optimization weights require names and integer values.', ucfirst($kind)));
      }

      $normalized[$name] = $weight;
    }

    return $normalized;
  }

  /** @param string[] $values @return array<string, true> */
  private static function normalizeSet(array $values): array
  {
    $normalized = [];

    foreach ($values as $value) {
      if (is_string($value) && self::normalize($value) !== '') {
        $normalized[self::normalize($value)] = true;
      }
    }

    return $normalized;
  }

  private static function normalize(string $value): string
  {
    return strtolower(trim($value));
  }
}
