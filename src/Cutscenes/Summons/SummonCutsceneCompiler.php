<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use JsonException;
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
    $segments = [];

    foreach ($definition->getTracks() as $track) {
      foreach ($track->getKeyframes() as $keyframe) {
        $segments[] = [
          'startFrame' => $keyframe->frame,
          'endFrame' => $keyframe->frame + $keyframe->duration - 1,
          'layer' => $track->type,
          'drawCommands' => [[
            'trackId' => $track->id,
            'position' => $keyframe->position,
            'content' => $keyframe->content,
            'assetId' => $keyframe->assetId,
            'color' => $keyframe->color,
            'visible' => $keyframe->visible,
            'zIndex' => $keyframe->zIndex,
            'blendMode' => $keyframe->blendMode,
            'easing' => $keyframe->easing,
            'payload' => $keyframe->payload,
          ]],
          'clearBeforeDraw' => boolval($keyframe->payload['clearBeforeDraw'] ?? false),
        ];
      }
    }

    usort(
      $segments,
      static function (array $left, array $right): int {
        if ($left['startFrame'] !== $right['startFrame']) {
          return $left['startFrame'] <=> $right['startFrame'];
        }

        $leftZ = intval($left['drawCommands'][0]['zIndex'] ?? 0);
        $rightZ = intval($right['drawCommands'][0]['zIndex'] ?? 0);

        if ($leftZ !== $rightZ) {
          return $leftZ <=> $rightZ;
        }

        return strval($left['layer']) <=> strval($right['layer']);
      },
    );

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

    if ($definition->effectTiming->mode === 'explicit_frame' && $definition->effectTiming->frame === null) {
      throw new InvalidArgumentException('Summon cutscene explicit frame timing requires a frame value.');
    }
  }
}
