<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use InvalidArgumentException;

/** Timed poses for selected battlers. This track has no combat result or cue. */
final class BattleBattlerReactionTrack
{
  public const array ROLES = [BattlePoseRole::DAMAGE->value, BattlePoseRole::HEAL->value];

  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence): array
  {
    CanvasCompositeValues::validateKeys($track, ['id', 'type', 'role', 'keyframes'], ['presentation']);
    $keys = $track['keyframes'];
    if ($playback !== 'once' || $cadence !== EffectCadence::FIXED || !in_array($track['role'], self::ROLES, true)
      || !is_array($keys) || !array_is_list($keys) || $keys === [] || count($keys) > 10000) {
      throw new InvalidArgumentException('Battler reaction requires a damage or heal role and ordered windows in a fixed once timeline.');
    }

    $end = 0;
    foreach ($keys as &$key) {
      if (!is_array($key)) { throw new InvalidArgumentException('Battler reaction windows must be objects.'); }
      CanvasCompositeValues::validateKeys($key, ['frame'], ['duration']);
      $duration = $key['duration'] ?? 1;
      if (!is_int($key['frame']) || $key['frame'] < $end || $key['frame'] >= $length
        || !is_int($duration) || $duration < 1 || $duration > $length - $key['frame']) {
        throw new InvalidArgumentException('Battler reaction windows must be ordered, disjoint and within the effect.');
      }
      $end = $key['frame'] + $duration;
      $key['payload'] = ['anchor' => 'target', 'role' => $track['role']];
    }
    unset($key);

    return ['id' => $track['id'], 'type' => EffectTrackType::BATTLER_REACTION->value, 'presentation' => 'graphical',
      'keyframes' => $keys];
  }
}
