<?php

namespace Ichiloto\Engine\Battle\Presentation;

/** Meaning assigned by the result formatter, independent of text and colour. */
enum BattleFeedbackRole: string
{
  case WEAK = 'weak';
  case RESIST = 'resist';
  case NULL = 'null';
  case ABSORB = 'absorb';
  case CRITICAL = 'critical';
  case DAMAGE = 'damage';
  case HEAL = 'heal';
  case MP_LOSS = 'mp_loss';
  case MP_GAIN = 'mp_gain';
  case KO = 'ko';
  case MISS = 'miss';
  case ZERO = 'zero';
}
