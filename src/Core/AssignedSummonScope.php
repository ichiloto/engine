<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

/** Scope names used by assigned_summons conditions in project data. */
enum AssignedSummonScope: string
{
  case PARTY = 'party';

  /** @return list<string> */
  public static function values(): array
  {
    return array_map(static fn(self $scope): string => $scope->value, self::cases());
  }
}
