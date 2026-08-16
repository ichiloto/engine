<?php

namespace Ichiloto\Engine\Entities\Skills;

/**
 * Defines how a multi-effect skill shares one random eligibility roll.
 *
 * A per-hit policy rolls for each repeated damaging effect. Per-target shares
 * one roll across every eligible effect aimed at the same battler. Per-action
 * shares one roll across the complete multi-target execution.
 */
enum SkillResolutionScope: string
{
  case PER_ACTION = 'perAction';
  case PER_TARGET = 'perTarget';
  case PER_HIT = 'perHit';
}
