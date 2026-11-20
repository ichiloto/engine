<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Localization\Vocabulary;

/** The starting resource values that a Battle Test fixture can override. */
enum BattleTestResource: string
{
  case HP = 'hp';
  case MP = 'mp';
  case AP = 'ap';

  public function getLabel(): string
  {
    return Vocabulary::getTerm('stats.' . $this->value);
  }

  public function getMaximum(Stats $stats): int
  {
    return match ($this) {
      self::HP => $stats->totalHp,
      self::MP => $stats->totalMp,
      self::AP => $stats->totalAp,
    };
  }

  public function getCurrent(Stats $stats): int
  {
    return match ($this) {
      self::HP => $stats->currentHp,
      self::MP => $stats->currentMp,
      self::AP => $stats->currentAp,
    };
  }

  public function apply(Stats $stats, BattleTestVital $amount): void
  {
    $points = $amount->resolvePoints($this->getMaximum($stats));
    match ($this) {
      self::HP => $stats->currentHp = $points,
      self::MP => $stats->currentMp = $points,
      self::AP => $stats->currentAp = $points,
    };
  }
}
