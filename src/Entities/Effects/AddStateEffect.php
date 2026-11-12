<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** An item grants an authored state through the battler's shared resistance and duration lifecycle. */
final class AddStateEffect extends BaseEffect
{
  protected(set) bool $lastApplied = false;

  public function __construct(string $name, string $description, protected(set) string $stateId,
    protected(set) int $chancePercent = 100, protected(set) bool $guaranteed = false)
  {
    $this->stateId = trim($stateId);
    if ($this->stateId === '' || $chancePercent < 0 || $chancePercent > 100
      || ($guaranteed && $chancePercent !== 100)) {
      throw new InvalidArgumentException('An add-state item effect requires a state id and chancePercent 0..100; guaranteed uses 100.');
    }
    parent::__construct($name, $description, $this->stateId, $chancePercent / 100, ValueBasis::ACTUAL);
  }

  /** The action supplies its random source and whether this is the recipient's current turn. */
  public function apply(EffectTarget $target, ?CombatRandomSource $random = null, bool $givenByBearer = false): void
  {
    $this->lastApplied = false;
    $state = StateRegistry::get($this->stateId);
    if ($state === null) {
      Debug::warn(sprintf('Cannot grant unknown state: %s', $this->stateId));
      return;
    }
    if (! $target->isKnockedOut && method_exists($target, 'addState')) {
      $this->lastApplied = $target->addState($state, $this->chancePercent, $random, $givenByBearer, $this->guaranteed);
    }
  }

  /** Apply a possible successful outcome only to the menu's detached probe, without randomness or live results. */
  public function applyForPreview(EffectTarget $target): void
  {
    $state = StateRegistry::get($this->stateId);
    if ($state !== null && ! $target->isKnockedOut && method_exists($target, 'getStateApplicationChance')
      && method_exists($target, 'addState') && $target->getStateApplicationChance($state, $this->chancePercent, $this->guaranteed) > 0) {
      $target->addState($state, guaranteed: true);
    }
  }

  public function jsonSerialize(): array
  {
    return ['type' => self::class, 'name' => $this->name, 'description' => $this->description,
      'stateId' => $this->stateId, 'chancePercent' => $this->chancePercent, 'guaranteed' => $this->guaranteed];
  }
}
