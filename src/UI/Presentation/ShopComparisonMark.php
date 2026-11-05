<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Quick comparison against the equipped item on the selected equipment's main stat. */
enum ShopComparisonMark: string
{
  case INCREASE = '↑';
  case DECREASE = '↓';
  case UNCHANGED = '=';
  case EQUIPPED = 'E';
  case MIXED = '↕';

  public function getColorRole(): string
  {
    return match ($this) {
      self::INCREASE => 'increase',
      self::DECREASE => 'decrease',
      self::EQUIPPED => 'accent',
      default => 'muted',
    };
  }
}
