<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Enumerations\EffectType;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\EffectInterface;

/**
 * The base effect class.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
abstract class BaseEffect implements EffectInterface
{
  /**
   * Constructs a new instance of the Effect.
   *
   * @param string $name The name of the effect.
   * @param string $description The description of the effect.
   * @param mixed $value The value of the effect.
   * @param float $successRate The success rate of the effect.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $description,
    protected(set) mixed $value,
    public float $successRate {
      get {
        return clamp($this->successRate, 0, 1);
      }
    },
    protected(set) ValueBasis $valueBasis
  )
  {
  }

  /**
   * @inheritdoc
   */
  public function jsonSerialize(): array
  {
    return [
      'type' => static::class,
      'name' => $this->name,
      'description' => $this->description,
      'value' => $this->value,
      'successRate' => $this->successRate,
      'valueBasis' => $this->valueBasis,
    ];
  }
}