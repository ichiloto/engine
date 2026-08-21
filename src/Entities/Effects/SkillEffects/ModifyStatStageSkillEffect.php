<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Buffs or debuffs a stat by shifting its stage.
 *
 * ```php
 * new ModifyStatStageSkillEffect('attack', 1)                       // buff the target
 * new ModifyStatStageSkillEffect('defence', 2, affectsUser: true)   // buff the caster
 * ```
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class ModifyStatStageSkillEffect extends SkillEffect
{
  /**
   * @param string $stat The stat to shift (attack, defence, magicAttack, magicDefence, speed, grace, evasion).
   * @param int $delta The stage shift (positive buffs, negative debuffs).
   * @param bool $affectsUser True to shift the caster instead of the target.
   */
  public function __construct(
    protected(set) string $stat,
    protected(set) int $delta,
    protected(set) bool $affectsUser = false,
  )
  {
    parent::__construct('0');
  }

  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    $recipients = $this->affectsUser
      ? [$context->user]
      : (is_array($context->target) ? $context->target : [$context->target]);

    foreach ($recipients as $recipient) {
      if (method_exists($recipient, 'addStatStage')) {
        $recipient->addStatStage($this->stat, $this->delta);
      }
    }
  }
}
