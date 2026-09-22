<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

use Assegai\Util\Path;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Process-wide project policy selected during game bootstrap. */
final class EquipmentOptimizationPolicyRegistry
{
  private const array KEYS = [
    'statWeights',
    'roleStatWeights',
    'slotStatWeights',
    'roleSlotStatWeights',
    'elementOutcomeWeights',
    'specialPropertyWeights',
    'excludedDefinitionIds',
    'excludedAvailabilities',
    'excludedAcquisitionPolicies',
  ];

  private static ?EquipmentOptimizationPolicyInterface $policy = null;

  /** Loads the existing Editor-authored project contract, never retaining a prior project's policy. */
  public static function configureFromProject(): void
  {
    self::reset();
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'equipment-optimization.php');

    if (! file_exists($filename) && ! is_link($filename)) {
      return;
    }

    try {
      if (! is_file($filename) || ! is_readable($filename)) {
        throw new InvalidArgumentException('The declaration must be a readable PHP file.');
      }

      $data = (static fn(): mixed => require $filename)();

      if (! is_array($data)) {
        throw new InvalidArgumentException('The declaration must return an array.');
      }

      foreach ($data as $key => $value) {
        if (! is_string($key) || ! in_array($key, self::KEYS, true)) {
          throw new InvalidArgumentException(sprintf('Unknown equipment optimization field: %s.', $key));
        }

        if (! is_array($value)) {
          throw new InvalidArgumentException(sprintf('Equipment optimization field %s must be an array.', $key));
        }
      }

      // An explicit empty declaration is a zero-weight policy, not the legacy fallback.
      self::configure(new DeclaredEquipmentOptimizationPolicy(...$data));
    } catch (Throwable $exception) {
      throw new RuntimeException(sprintf(
        'Equipment optimization policy %s could not be loaded: %s',
        $filename,
        $exception->getMessage(),
      ), previous: $exception);
    }
  }

  public static function configure(?EquipmentOptimizationPolicyInterface $policy): void
  {
    self::$policy = $policy;
  }

  public static function current(): EquipmentOptimizationPolicyInterface
  {
    return self::$policy ?? new LegacyEqualWeightEquipmentOptimizationPolicy();
  }

  public static function reset(): void
  {
    self::$policy = null;
  }
}
