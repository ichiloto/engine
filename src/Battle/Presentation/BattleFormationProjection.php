<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Formation\BattleRow;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Projects logical movement onto renderer-owned starting anchors without changing combat geometry. */
final class BattleFormationProjection
{
  /** @param list<array{battler: CharacterInterface, point: Vector2}> $anchors One side's starting artwork positions.
   * The signed row step points from front to back; lane step is in that renderer's units. */
  public static function project(BattleFormation $formation, CharacterInterface $member, array $anchors,
    float $rowStep, float $laneStep): Vector2
  {
    if (!is_finite($rowStep) || !is_finite($laneStep)) { throw new \InvalidArgumentException('Formation presentation steps must be finite.'); }
    $own = array_find($anchors, static fn(array $entry): bool => $entry['battler'] === $member)
      ?? throw new \InvalidArgumentException('A formation projection requires the participant starting anchor.');
    $initial = $formation->getInitialPosition($member);
    $current = $formation->getPosition($member);
    if ($initial === null || $current === null) { throw new \InvalidArgumentException('Formation presentation requires a current battle participant.'); }
    if ($initial == $current) { return clone $own['point']; }
    foreach ($anchors as $entry) {
      if ($formation->getInitialPosition($entry['battler']) == $current) { return clone $entry['point']; }
    }
    $row = static fn($position): int => $position->row === BattleRow::FRONT ? 0 : 1;
    return new Vector2($own['point']->x + ($row($current) - $row($initial)) * $rowStep,
      $own['point']->y + ($current->lane - $initial->lane) * $laneStep);
  }
}
