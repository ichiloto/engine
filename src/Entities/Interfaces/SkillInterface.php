<?php

namespace Ichiloto\Engine\Entities\Interfaces;

/**
 * Represents the skill interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface SkillInterface
{
  /**
   * Executes the skill.
   *
   * @param SkillContextInterface|null $context The skill context.
   */
  public function execute(?SkillContextInterface $context): void;
}