<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Insight;

/**
 * Keeps the facts revealed about a battler. A battle fights a copy of each
 * foe, so what is revealed ends with the battle.
 *
 * @package Ichiloto\Engine\Battle\Insight
 */
trait HasRevealedFacts
{
  /** @var array<string, RevealFact> The revealed facts, keyed by value. */
  protected(set) array $revealedFacts = [];

  /** @inheritDoc */
  public function revealFacts(RevealFact ...$facts): array
  {
    $new = [];
    foreach ($facts as $fact) {
      if (! isset($this->revealedFacts[$fact->value])) {
        $this->revealedFacts[$fact->value] = $fact;
        $new[] = $fact;
      }
    }

    return $new;
  }

  /** @inheritDoc */
  public function isFactRevealed(RevealFact $fact): bool
  {
    return isset($this->revealedFacts[$fact->value]);
  }
}
