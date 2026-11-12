<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Project-owned roles selected independently of actor names and filenames. */
final readonly class BattlePoseSet
{
  /**
   * @param array<string, BattlerPose> $roles BattlePoseRole values.
   * @param array<int, BattlerPose> $actionPoses Animation IDs with a command-owned pose.
   * @param ?float $displayWidth Resting/base artwork width in arena units; poses inherit its pixel scale.
   */
  public function __construct(public array $roles, public ?float $displayWidth = null, public array $actionPoses = [])
  {
    if ($displayWidth !== null && (!is_finite($displayWidth) || $displayWidth <= 0 || $displayWidth > 16384)) {
      throw new InvalidArgumentException('Registered pose display width must be finite and within 0..16384.');
    }
    foreach ($roles as $role => $pose) {
      if (!is_string($role) || BattlePoseRole::tryFrom($role) === null || !$pose instanceof BattlerPose) {
        throw new InvalidArgumentException('Battle pose sets require known role names and typed pose definitions.');
      }
      if (($role === BattlePoseRole::MOVE->value && !$pose->loop)
        || (in_array($role, [BattlePoseRole::MOVE->value, BattlePoseRole::RETURNING->value], true) && $pose->impactFrame !== null)) {
        throw new InvalidArgumentException('Advance poses must loop; movement poses carry no action impact anchor.');
      }
      if ($pose->guardFrame !== null || $pose->farPose !== null || $pose->arrivalPoses !== []) {
        throw new InvalidArgumentException('Guard frames and arrival variants belong to action pose bindings.');
      }
      if (($pose->entryFrames !== [] || $pose->exitFrames !== [])
        && !in_array($role, [BattlePoseRole::MOVE->value, BattlePoseRole::RETURNING->value], true)) {
        throw new InvalidArgumentException('Battle pose entry/exit sequences are supported only for move and return roles.');
      }
    }
    foreach ($actionPoses as $animationId => $pose) {
      if (!is_int($animationId) || $animationId < 1 || !$pose instanceof BattlerPose
        || $pose->impactFrame === null) {
        throw new InvalidArgumentException('Action poses require positive animation IDs and an impact entry on a once-playing pose.');
      }
      if (($pose->guardFrame !== null || $pose->entryFrames !== [] || $pose->exitFrames !== [])
        && ($pose->impactFrame !== 0 || $pose->entryFrames === [] || $pose->exitFrames === [])) {
        throw new InvalidArgumentException('Action transit cells require both entry and exit sequences and a planted first frame.');
      }
    }
  }

  public function getAnimationPose(?int $animationId): ?BattlerPose
  {
    return $animationId === null ? null : ($this->actionPoses[$animationId] ?? null);
  }

  public function getPose(BattlePoseRole $role): ?BattlerPose
  {
    return $this->roles[$role->value] ?? null;
  }
}
