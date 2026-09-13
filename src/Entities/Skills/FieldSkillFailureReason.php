<?php

namespace Ichiloto\Engine\Entities\Skills;

/** Why a requested field skill did not commit. */
enum FieldSkillFailureReason: string
{
  case WRONG_OCCASION = 'wrong_occasion';
  case CASTER_KNOCKED_OUT = 'caster_knocked_out';
  case INSUFFICIENT_MP = 'insufficient_mp';
  case UNSUPPORTED_SCOPE = 'unsupported_scope';
  case TARGET_REQUIRED = 'target_required';
  case INVALID_TARGET = 'invalid_target';
  case NO_ELIGIBLE_TARGETS = 'no_eligible_targets';
  case NO_EFFECTS = 'no_effects';
  case NO_EFFECT = 'no_effect';
}
