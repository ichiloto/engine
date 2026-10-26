<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use InvalidArgumentException;

/** One editable project reference shared by every arena, formation and battler role. */
final readonly class BattleScale
{
  /** @var array<string, BattlerScale> */
  public array $actors;
  /** @var array<string, BattlerScale> */
  public array $enemies;

  public function __construct(
    public string $referenceActorId,
    public float $referenceHeight,
    array $actors,
    array $enemies = [],
  ) {
    CanvasValidation::id($referenceActorId);
    if (!is_finite($referenceHeight) || $referenceHeight <= 0 || $referenceHeight > 16384) {
      throw new InvalidArgumentException('Reference body height must be finite within 0..16384.');
    }
    $this->actors = self::getValidatedProfiles($actors);
    $this->enemies = self::getValidatedProfiles($enemies);
    $reference = $this->actors[$referenceActorId] ?? null;
    if ($reference === null || $reference->relativeSize !== 1.0 || $reference->horizontal) {
      throw new InvalidArgumentException('Battle scale requires a registered unit-height reference actor.');
    }
  }

  public function getProfile(string $id, bool $party): ?BattlerScale
  {
    return ($party ? $this->actors : $this->enemies)[$id] ?? null;
  }

  /** @param array<string, BattlerScale> $profiles @return array<string, BattlerScale> */
  private static function getValidatedProfiles(array $profiles): array
  {
    foreach ($profiles as $id => $profile) {
      if (!is_string($id) || !$profile instanceof BattlerScale) {
        throw new InvalidArgumentException('Battle scale requires stable string identities and typed body profiles.');
      }
      CanvasValidation::id($id);
    }
    return $profiles;
  }
}
