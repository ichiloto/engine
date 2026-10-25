<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Insight\RevealableBattler;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use InvalidArgumentException;

/**
 * Reveals facts about each target for the rest of the battle.
 *
 * ```php
 * new RevealSkillEffect([RevealFact::HP], 75)   // a 75% chance to read the target's HP
 * ```
 *
 * One roll per target decides whether every listed fact is revealed or
 * none is; no resistance scales it, so no target is immune. A failed roll
 * reveals nothing; the caster's MP and turn are spent as for any skill.
 * Each fact becomes a secondary outcome (`reveal`, the fact's value,
 * whether it was revealed), which the battle reads to show the facts and to
 * record them for the target's kind. The party's own facts are always
 * shown, so only targets that keep revealed facts take part.
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class RevealSkillEffect extends SkillEffect
{
  /** The secondary outcome type a reveal records. */
  public const string OUTCOME = 'reveal';

  /** @var list<RevealFact> The facts it reveals. */
  protected(set) array $facts;

  /**
   * @param list<RevealFact|string> $facts The facts it reveals.
   * @param int $chancePercent The chance, 1 to 100, that a target's facts are revealed.
   * @throws InvalidArgumentException When no fact is named, a fact is unknown or repeated, or the chance is outside 1 to 100.
   */
  public function __construct(array $facts, protected(set) int $chancePercent = 100)
  {
    parent::__construct('0');
    $this->facts = RevealFact::readList($facts);
    if ($chancePercent < 1 || $chancePercent > 100) {
      throw new InvalidArgumentException('A reveal chance is a whole percentage from 1 to 100.');
    }
  }

  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    foreach (is_array($context->target) ? $context->target : [$context->target] as $target) {
      if (! $target instanceof RevealableBattler) {
        continue;
      }
      $revealed = $this->chancePercent >= 100 || $context->random->nextInt(1, 100) <= $this->chancePercent;
      if ($revealed) {
        $target->revealFacts(...$this->facts);
      }
      foreach ($this->facts as $fact) {
        $context->recordSecondaryOutcome(self::OUTCOME, $fact->value, $revealed);
      }
    }
  }
}
