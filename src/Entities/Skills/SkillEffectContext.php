<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Represents the skill effect context.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
class SkillEffectContext
{
  /**
   * SkillEffectContext constructor.
   *
   * @param CharacterInterface $user
   * @param CharacterInterface|CharacterInterface[] $target
   */
  public function __construct(
    protected(set) CharacterInterface $user,
    protected(set) CharacterInterface|array $target,
  )
  {
  }
}