<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Insight;

/**
 * A battler whose facts the player does not see until they are revealed.
 * The player always sees the party's own HP, MP and stats, so only foes
 * keep revealed facts. What is revealed lasts for the battle the battler
 * fights in.
 *
 * @package Ichiloto\Engine\Battle\Insight
 */
interface RevealableBattler
{
  /**
   * Marks facts known for the rest of the battle.
   *
   * @return list<RevealFact> The facts that were not known before.
   */
  public function revealFacts(RevealFact ...$facts): array;

  /** Whether a fact has been revealed in this battle. */
  public function isFactRevealed(RevealFact $fact): bool;
}
