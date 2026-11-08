<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

/** Scope names used by present_party_size conditions in project data. */
enum PresentPartyScope: string
{
  case PARTY = 'party';

  /** @return list<string> */
  public static function values(): array
  {
    return array_map(static fn(self $scope): string => $scope->value, self::cases());
  }
}
