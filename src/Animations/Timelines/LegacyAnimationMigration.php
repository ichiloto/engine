<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Animations\Animation;
use InvalidArgumentException;

/** Lossless source conversion; callers own timing decisions and transactional file writes. */
final class LegacyAnimationMigration
{
  /**
   * The rest frame is zero-based on the original frame grid, including any flash tail.
   * Fixed cadence requires explicit fps; battle cadence must omit it. Multiple ticks
   * can represent exact consumer timing (for example, three ticks at 25 fps = .12 s).
   * @return array<string, mixed>
   */
  public static function getTimelineData(string $id, Animation $animation, EffectCadence $cadence,
    int $ticksPerFrame, int $restFrame, ?int $fps = null, bool $includeFlash = true): array
  {
    EffectTimelineLibrary::assertId($id);
    if ($ticksPerFrame < 1 || $ticksPerFrame > 100000 || $animation->maxFrames > 100000
      || $animation->maxFrames < 1 || ($cadence === EffectCadence::FIXED && $fps === null)
      || ($cadence === EffectCadence::BATTLE_PHASE && $fps !== null)) {
      throw new InvalidArgumentException('Animation migration requires explicit consumer cadence and a bounded positive tick multiplier.');
    }
    $tracks = $cues = [];
    $length = $animation->maxFrames;
    foreach ($animation->getFrames() as $frame) {
      foreach ($frame->getCells() as $index => $cell) {
        // Track painter order, not position: moving, overlapping symbols must retain frame order.
        $key = 'cell:' . $index;
        if (!isset($tracks[$key])) {
          $tracks[$key] = ['id' => 'cell-' . $index, 'type' => 'glyph', 'anchor' => 'target',
            'keyframes' => []];
        }
        $tracks[$key]['keyframes'][] = ['frame' => ($frame->index - 1) * $ticksPerFrame,
          'duration' => $ticksPerFrame, 'position' => ['x' => $cell->x, 'y' => $cell->y],
          'content' => $cell->symbol, ...($cell->color === null ? [] : ['color' => $cell->color]),
          'payload' => ['legacyPosition' => $animation->position->value]];
      }
      $cue = $animation->getCue($frame->index);
      if ($cue !== null && $cue->soundEffect !== '') {
        $cues[] = ['id' => 'sound-' . $frame->index, 'frame' => ($frame->index - 1) * $ticksPerFrame,
          'type' => 'playSound', 'payload' => ['sound' => $cue->soundEffect]];
      }
      if ($includeFlash && $cue?->flashColor !== null && $cue->flashDurationFrames > 0) {
        $length = max($length, $frame->index - 1 + $cue->flashDurationFrames);
        $tracks['flash:' . $frame->index] = ['id' => 'flash-' . $frame->index, 'type' => 'flash',
          'anchor' => $animation->position->value === 'screen' ? 'screen' : 'target',
          'keyframes' => [['frame' => ($frame->index - 1) * $ticksPerFrame,
            'duration' => $cue->flashDurationFrames * $ticksPerFrame, 'color' => $cue->flashColor]]];
      }
      if (count($tracks) > 32) {
        throw new InvalidArgumentException('Animation migration exceeds the shared 32-track limit; no cells or overlapping flashes were discarded.');
      }
    }
    if ($restFrame < 0 || $restFrame >= $length || $length * $ticksPerFrame > 100000) {
      throw new InvalidArgumentException('Animation migration requires a rest frame within its complete bounded duration.');
    }
    // A sound-only or blank sequence must retain its duration without inventing a visible glyph.
    if ($tracks === []) {
      $tracks[] = ['id' => 'empty', 'type' => 'glyph', 'anchor' => 'target',
        'keyframes' => [['frame' => 0, 'duration' => $length * $ticksPerFrame, 'visible' => false]]];
    }
    $data = [
      ...($cadence === EffectCadence::FIXED ? ['fps' => $fps] : ['cadence' => $cadence->value]),
      'lengthFrames' => $length * $ticksPerFrame,
      'restFrame' => $restFrame * $ticksPerFrame,
      'tracks' => array_values($tracks), 'cues' => $cues,
    ];
    (new EffectTimelineLibrary(''))->compile($id, $data, forBattle: true);
    return $data;
  }
}
