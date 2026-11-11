<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

/** Persistent counters maintained by the Engine's gameplay owners. */
enum PlayStatistic: string
{
  case BATTLES_WON = 'battles_won';

  /** The statistic's player-facing name in authoring choices. */
  public function getLabel(): string
  {
    return match ($this) {
      self::BATTLES_WON => 'Battles Won',
    };
  }

  /** @return list<string> Stable names available to condition authoring. */
  public static function values(): array
  {
    return array_column(self::cases(), 'value');
  }
}
