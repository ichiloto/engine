<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Project-owned roles selected independently of actor names and filenames. */
final readonly class BattlePoseSet
{
  /** @param array<string, BattlerPose> $roles BattlePoseRole values. */
  public function __construct(public array $roles, public ?float $displayWidth = null)
  {
    if ($displayWidth !== null && (!is_finite($displayWidth) || $displayWidth <= 0 || $displayWidth > 16384)) {
      throw new InvalidArgumentException('Registered pose display width must be finite and within 0..16384.');
    }
    foreach ($roles as $role => $pose) {
      if (!is_string($role) || BattlePoseRole::tryFrom($role) === null || !$pose instanceof BattlerPose) {
        throw new InvalidArgumentException('Battle pose sets require known role names and typed pose definitions.');
      }
    }
  }

  public function getPose(BattlePoseRole $role): ?BattlerPose
  {
    return $this->roles[$role->value] ?? null;
  }
}
