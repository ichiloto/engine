<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * The interface for an entity that can have an effect.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface EffectInterface
{
  /**
   * @var string The name of the effect.
   */
  public string $name {
    get;
  }
  /**
   * @var string The description of the effect.
   */
  public string $description {
    get;
  }
  /**
   * @var mixed The value of the effect.
   */
  public mixed $value {
    get;
  }
  /**
   * @var float The success rate of the effect.
   */
  public float $successRate {
    get;
  }
  /**
   * @var ValueBasis The basis of the value.
   */
  public ValueBasis $valueBasis {
    get;
  }

  /**
   * Applies the effect to the target.
   *
   * @param EffectTarget $target The target of the effect.
   * @return void
   */
  public function apply(EffectTarget $target): void;
}