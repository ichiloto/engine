<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use JsonException;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineCompiler;
use InvalidArgumentException;

/**
 * Compiles authored summon cutscene sources into runtime playback data.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneCompiler
{
  public function __construct(
    public int $compileVersion = 1,
  )
  {
    $this->compileVersion = max(1, $compileVersion);
  }

  /**
   * @throws JsonException
   */
  public function compile(SummonCutsceneDefinition $definition): SummonCompiledCutscene
  {
    $this->assertValidEffectTiming($definition);

    $source = $definition->toSourceArray();
    $sourceHash = sha1(json_encode($source, JSON_THROW_ON_ERROR));
    $segments = (new EffectTimelineCompiler())->compileTracks(array_map(
      static fn(SummonCutsceneTrack $track): array => $track->toArray(), $definition->getTracks(),
    ));

    $cueSchedule = array_map(
      static fn(SummonCue $cue): array => $cue->toArray(),
      $definition->getCues(),
    );

    return new SummonCompiledCutscene(
      $definition->id,
      $sourceHash,
      $this->compileVersion,
      $definition->fps,
      $segments,
      $cueSchedule,
      [
        'in' => $definition->transitionIn->toArray(),
        'out' => $definition->transitionOut->toArray(),
      ],
      [
        'playback' => $definition->playback->toArray(),
        'name' => $definition->name,
        'moveName' => $definition->moveName,
        'targetPresentation' => $definition->targetPresentation->toArray(),
        'effectTiming' => $definition->effectTiming->toArray(),
        'lengthFrames' => $definition->lengthFrames,
      ],
    );
  }

  protected function assertValidEffectTiming(SummonCutsceneDefinition $definition): void
  {
    if ($definition->effectTiming->mode === 'cue') {
      if ($definition->effectTiming->cueId === null || $definition->effectTiming->cueId === '') {
        throw new InvalidArgumentException('Summon cutscene cue timing requires a cueId.');
      }

      if ($definition->getCueById($definition->effectTiming->cueId) === null) {
        throw new InvalidArgumentException('Summon cutscene effect cue does not exist.');
      }
    }

    if (in_array($definition->effectTiming->mode, ['explicit_frame', 'frame'], true) && $definition->effectTiming->frame === null) {
      throw new InvalidArgumentException('Summon cutscene explicit frame timing requires a frame value.');
    }
  }
}
