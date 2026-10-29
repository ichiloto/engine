<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities;

enum RecruitmentLevelPolicy: string
{
  case AUTHORED = 'authored';
  case PARTY_AVERAGE = 'partyAverage';
}
