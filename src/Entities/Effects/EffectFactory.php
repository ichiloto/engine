<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\EffectInterface;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use stdClass;

/**
 * The EffectFactory class.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class EffectFactory
{
  /**
   * Creates an effect.
   *
   * @param class-string $type The type of effect to create.
   * @param array<string, mixed> $args The arguments to pass to the effect.
   * @return EffectInterface The created effect.
   * @throws ReflectionException If the reflection fails.
   */
  public static function create(string $type, array|object $args = []): EffectInterface
  {
    if (! class_exists($type) ) {
      throw new InvalidArgumentException("Unknown effect type: $type");
    }
    $reflectionClass = new ReflectionClass($type);

    if (is_object($args)) {
      $args = (array) $args;
    }

    if (isset($args['type'])) {
      unset($args['type']);
    }

    if (! $args['valueBasis'] instanceof ValueBasis) {
      $args['valueBasis'] = ValueBasis::tryFrom($args['valueBasis']) ?? throw new InvalidArgumentException("Invalid value basis: {$args['valueBasis']}");
    }

    return match($type) {
      HPRecoveryEffect::class,
      MPRecoveryEffect::class,
      MaxHPIncrementEffect::class,
      MaxMPIncrementEffect::class => $reflectionClass->newInstanceArgs($args),
      default => throw new InvalidArgumentException("Unknown effect type: $type"),
    };
  }

  /**
   * Returns a list of effects from the given list of objects.
   *
   * @param stdClass[] $objects The list of objects.
   * @return EffectInterface[] The list of effects.
   * @throws ReflectionException If the effect class does not exist.
   */
  public static function createFromObjects(array $objects): array
  {
    $effects = [];
    foreach ($objects as $effect) {
      if (isset($effect->type)) {
        $effects[] = EffectFactory::create($effect->type, $effect);
      }
    }
    return $effects;
  }
}