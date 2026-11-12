<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

enum TutorialReferenceSource: string
{
  case ACTOR = 'actor';
  case SUMMON = 'summon';
  case FIELD_MAGIC = 'field_magic';
  case MASTERY_REGION = 'mastery_region';
  case MASTERY_FAMILY = 'mastery_family';
  case MASTERY_NODE = 'mastery_node';
}
