<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;
use InvalidArgumentException;

/**
 * An item effect that cures states on its target, such as an Antidote
 * curing poison. The cure works on whatever the target bears from the
 * listed states; a target bearing none of them is unchanged.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class RemoveStateEffect extends BaseEffect
{
  /**
   * @var list<string> The ids of the states the effect cures.
   */
  public array $stateIds {
    get {
      return $this->value;
    }
  }

  /**
   * @param string $name The name of the effect.
   * @param string $description The description of the effect.
   * @param list<string> $stateIds The ids of the states the effect cures.
   */
  public function __construct(string $name, string $description, array $stateIds)
  {
    if ($stateIds === [] || ! array_is_list($stateIds)
      || array_filter($stateIds, static fn(mixed $id): bool => ! is_string($id) || trim($id) === '') !== []) {
      throw new InvalidArgumentException('A remove-state effect must list the states it cures.');
    }

    parent::__construct($name, $description, array_values(array_unique(array_map('trim', $stateIds))), 1.0, ValueBasis::ACTUAL);
  }

  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if (! method_exists($target, 'removeState')) {
      return;
    }

    foreach ($this->stateIds as $stateId) {
      $target->removeState($stateId);
    }
  }
}
