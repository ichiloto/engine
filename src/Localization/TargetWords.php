<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\ItemScope;

/**
 * Whom a skill or item reaches, in the player's words ("One foe", "All
 * allies"), from the project's vocabulary (`vocab.targets.*`) with the
 * Engine's defaults. Every screen that names a reach says it the same way.
 */
final class TargetWords
{
  private function __construct()
  {
  }

  /** The words for a reach, or an empty string for a reach that targets nobody. */
  public static function describeScope(ItemScope $scope): string
  {
    $fallen = $scope->status === ItemScopeStatus::DEAD;
    $path = match ($scope->side) {
      ItemScopeSide::NONE => null,
      ItemScopeSide::USER => 'targets.self',
      ItemScopeSide::ENEMY => match ($scope->number) {
        ItemScopeNumber::ONE => 'targets.one_foe',
        ItemScopeNumber::ALL => 'targets.all_foes',
        ItemScopeNumber::RANDOM => 'targets.multiple_foes',
      },
      ItemScopeSide::ALLY => match ($scope->number) {
        ItemScopeNumber::ONE => $fallen ? 'targets.one_fallen_ally' : 'targets.one_ally',
        ItemScopeNumber::ALL => $fallen ? 'targets.all_fallen_allies' : 'targets.all_allies',
        ItemScopeNumber::RANDOM => 'targets.multiple_allies',
      },
      ItemScopeSide::ENEMY_ALLY => $scope->number === ItemScopeNumber::ALL ? 'targets.all_targets' : 'targets.one_target',
    };

    return $path === null ? '' : Vocabulary::getTerm($path);
  }
}
