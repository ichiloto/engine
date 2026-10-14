<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Throwable;

/** Bounded modulo sampling of an owned battle clock; no traversal or cue dispatch. */
final class BattlerConditionEffect implements BattleEffectPlayback
{
  public readonly array $targets;
  public private(set) bool $hasPresentationFailure = false;

  public function __construct(public readonly CharacterInterface $actor, private readonly array $entries,
    private readonly BattleConditionEffects $effects, private readonly float $seconds, private readonly bool $useBindings = true)
  {
    $this->targets = [$actor];
  }

  public function getActiveSegments(bool $reducedMotion = false, bool $terminal = false): array
  {
    $segments = [];
    foreach ($this->entries as $index => $entry) {
      $timeline = $this->hasPresentationFailure || !$this->useBindings ? null : $this->effects->getTimeline($entry['key'], $terminal);
      $active = [];
      if ($timeline !== null) {
        $timing = new EffectPlaybackTiming($timeline);
        $length = $timing->totalFrames;
        $loopFrom = (int)($timeline->defaults['playback']['loopFrom'] ?? 0);
        $intro = $loopFrom * $timing->secondsPerFrame;
        $frame = $reducedMotion ? (int)($timeline->defaults['restFrame'] ?? 0)
          : ($this->seconds < $intro ? $timing->getFrameCountAt($this->seconds)
            : $loopFrom + $timing->getFrameCountAt(fmod($this->seconds - $intro,
              ($length - $loopFrom) * $timing->secondsPerFrame)) % ($length - $loopFrom));
        foreach ($timeline->playbackSegments as $segment) {
          if ($segment['startFrame'] <= $frame && $frame <= $segment['endFrame']) {
            foreach ($segment['drawCommands'] as &$command) {
              $command['trackId'] = 'condition-' . $index . '-' . $command['trackId'];
            }
            unset($command);
            $active[] = $segment;
          }
        }
      }
      // Graphical semantics belong to persistent badges, never optional animation.
      if ($terminal && ($timeline === null || $timeline->playbackSegments === [])) {
        $active[] = ['layer' => 'text', 'presentation' => 'all', 'drawCommands' => [[
          'trackId' => 'condition-' . $index, 'visible' => true, 'content' => $entry['glyph'],
          'position' => ['x' => 0, 'y' => -1 - $index],
          'payload' => ['anchor' => 'target', 'attachment' => 'head', 'legacyPosition' => 'head'],
        ]]];
      }
      array_push($segments, ...EffectSegmentComposition::compose($active,
        $terminal ? EffectPresentation::TERMINAL : EffectPresentation::GRAPHICAL));
    }
    return $segments;
  }

  public function recordPresentationFailure(Throwable $failure): void
  {
    $this->hasPresentationFailure = true;
    $this->effects->reportFailure('battler:' . spl_object_id($this->actor), $failure);
  }
}
