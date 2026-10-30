<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities;

enum RecruitmentPointPolicy: string
{
    case AUTHORED = 'authored';
    case PARTY_AVERAGE_EARNED = 'partyAverageEarned';
    case PARTY_AVERAGE_ESTIMATED_EARNED = 'partyAverageEstimatedEarned';
}
