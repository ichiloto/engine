<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use JsonException;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineValidator;

/**
 * Compiles authored summon cutscene sources into runtime playback data.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneCompiler
{
  public const int VERSION = 4;

  public function __construct(
    public int $compileVersion = self::VERSION,
    private readonly ?string $assetRoot = null,
  )
  {
    $this->compileVersion = max(1, $compileVersion);
  }

  /**
   * @throws JsonException
   */
  public function compile(SummonCutsceneDefinition $definition,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL): SummonCompiledCutscene
  {
    $source = $definition->toSourceArray();
    $timeline = $definition->toTimelineArray();
    unset($timeline['formatVersion'], $timeline['editor']);
    if (isset($timeline['presentations'])) {
      $variants = $timeline['presentations'];
      if (is_array($variants[$presentation->value] ?? null)) {
        $variants[$presentation->value] = $this->prepareSequence($definition, $variants[$presentation->value]);
      }
      $timeline = ['presentations' => $variants];
    } else {
      $timeline = $this->prepareSequence($definition, $timeline);
    }
    $effect = (new EffectTimelineValidator($this->assetRoot ?? getcwd() . '/assets'))
      ->compile($definition->id, $timeline, true, $presentation, forSummon: true);
    // Covers belong to the selected stage, not the common source's legacy fades.
    $stageOwnsCovers = isset($effect->defaults['stage']);
    $sourceHash = sha1(json_encode($source, JSON_THROW_ON_ERROR));

    return new SummonCompiledCutscene(
      $definition->id,
      $sourceHash,
      $this->compileVersion,
      $effect->fps,
      $effect->playbackSegments,
      $effect->cueSchedule,
      [
        'in' => [...$definition->transitionIn->toArray(),
          'durationMs' => $stageOwnsCovers ? 0 : $definition->transitionIn->durationMs],
        'out' => [...$definition->transitionOut->toArray(),
          'durationMs' => $stageOwnsCovers ? 0 : $definition->transitionOut->durationMs],
      ],
      [
        ...$effect->defaults,
        'presentation' => $presentation->value,
        'playback' => $definition->playback->toArray(),
        'name' => $definition->name,
        'moveName' => $definition->moveName,
        'targetPresentation' => $definition->targetPresentation->toArray(),
        'effectTiming' => $definition->effectTiming->toArray(),
      ],
    );
  }

  /** Summon policy stays outside the common sequence; its impact uses the same validator. */
  private function prepareSequence(SummonCutsceneDefinition $definition, array $sequence): array
  {
    // Flat and paired sources use the same legacy summon normalization without rewriting authored source.
    $sequence['tracks'] = array_map(static fn(array $track): array => SummonCutsceneTrack::fromArray($track)->toArray(),
      array_values(array_filter($sequence['tracks'] ?? [], 'is_array')));
    $sequence['cues'] = array_map(static fn(array $cue): array => SummonCue::fromArray($cue)->toArray(),
      array_values(array_filter($sequence['cues'] ?? [], 'is_array')));
    $timing = $definition->effectTiming->toArray();
    if ($timing['mode'] === 'explicit_frame') { $timing['mode'] = 'frame'; }
    return [...$sequence, 'playback' => 'once',
      'restFrame' => $sequence['restFrame'] ?? max(0, ($sequence['lengthFrames'] ?? 1) - 1),
      'effectTiming' => $timing];
  }
}
