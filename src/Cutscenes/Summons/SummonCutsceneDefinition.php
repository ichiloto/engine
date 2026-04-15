<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents one authored summon cutscene definition.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneDefinition
{
  /**
   * @var string[]
   */
  public array $tags = [];
  /**
   * @var SummonCutsceneTrack[]
   */
  protected array $tracks = [];
  /**
   * @var array<string, SummonCue>
   */
  protected array $cues = [];

  /**
   * @param string[] $tags
   * @param SummonCutsceneTrack[] $tracks
   * @param SummonCue[] $cues
   * @param array<string, mixed> $editor
   * @param array<string, mixed> $authoring
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $description = '',
    public int $version = 1,
    public ?string $linkedSummonId = null,
    public ?string $linkedActionId = null,
    array $tags = [],
    public ?SummonPlaybackConfig $playback = null,
    public ?SummonTransitionDefinition $transitionIn = null,
    public ?SummonTransitionDefinition $transitionOut = null,
    public ?SummonEffectTiming $effectTiming = null,
    public ?SummonTargetPresentation $targetPresentation = null,
    public int $formatVersion = 1,
    public int $fps = 24,
    public int $lengthFrames = 1,
    array $tracks = [],
    array $cues = [],
    public array $editor = [],
    public array $authoring = [],
  )
  {
    $this->id = trim($id);
    $this->name = trim($name) !== '' ? trim($name) : 'New Summon';
    $this->description = trim($description);
    $this->version = max(1, $version);
    $this->linkedSummonId = $this->normalizeOptionalString($linkedSummonId);
    $this->linkedActionId = $this->normalizeOptionalString($linkedActionId);
    $this->tags = array_values(array_filter(array_map('strval', $tags), static fn(string $tag): bool => trim($tag) !== ''));
    $this->playback = $playback ?? new SummonPlaybackConfig();
    $this->transitionIn = $transitionIn ?? new SummonTransitionDefinition('fadeToBlack', 0);
    $this->transitionOut = $transitionOut ?? new SummonTransitionDefinition('fadeFromBlack', 0);
    $this->effectTiming = $effectTiming ?? new SummonEffectTiming();
    $this->targetPresentation = $targetPresentation ?? new SummonTargetPresentation();
    $this->formatVersion = max(1, $formatVersion);
    $this->fps = max(1, $fps);
    $this->lengthFrames = max(1, $lengthFrames);

    foreach ($tracks as $track) {
      if ($track instanceof SummonCutsceneTrack) {
        $this->tracks[] = $track;
      }
    }

    foreach ($cues as $cue) {
      if ($cue instanceof SummonCue && $cue->id !== '') {
        $this->cues[$cue->id] = $cue;
      }
    }
  }

  /**
   * @param array<string, mixed> $data
   * @param array<string, mixed> $timeline
   * @return self
   */
  public static function fromArrays(array $data, array $timeline): self
  {
    $tracks = array_map(
      static fn(array $track): SummonCutsceneTrack => SummonCutsceneTrack::fromArray($track),
      array_values(array_filter($timeline['tracks'] ?? [], 'is_array')),
    );
    $cues = array_map(
      static fn(array $cue): SummonCue => SummonCue::fromArray($cue),
      array_values(array_filter($timeline['cues'] ?? [], 'is_array')),
    );

    return new self(
      strval($data['id'] ?? ''),
      strval($data['name'] ?? 'New Summon'),
      strval($data['description'] ?? ''),
      intval($data['version'] ?? 1),
      isset($data['linkedSummonId']) ? strval($data['linkedSummonId']) : null,
      isset($data['linkedActionId']) ? strval($data['linkedActionId']) : null,
      array_values(array_filter($data['tags'] ?? [], static fn(mixed $item): bool => is_scalar($item))),
      SummonPlaybackConfig::fromArray(is_array($data['playback'] ?? null) ? $data['playback'] : []),
      SummonTransitionDefinition::fromArray(is_array($data['transitionIn'] ?? null) ? $data['transitionIn'] : []),
      SummonTransitionDefinition::fromArray(is_array($data['transitionOut'] ?? null) ? $data['transitionOut'] : []),
      SummonEffectTiming::fromArray(is_array($data['effectTiming'] ?? null) ? $data['effectTiming'] : []),
      SummonTargetPresentation::fromArray(is_array($data['targetPresentation'] ?? null) ? $data['targetPresentation'] : []),
      intval($timeline['formatVersion'] ?? 1),
      intval($timeline['fps'] ?? 24),
      intval($timeline['lengthFrames'] ?? 1),
      $tracks,
      $cues,
      is_array($timeline['editor'] ?? null) ? $timeline['editor'] : [],
      is_array($data['authoring'] ?? null) ? $data['authoring'] : [],
    );
  }

  /**
   * @return SummonCutsceneTrack[]
   */
  public function getTracks(): array
  {
    return array_values($this->tracks);
  }

  /**
   * @return SummonCue[]
   */
  public function getCues(): array
  {
    $cues = array_values($this->cues);
    usort($cues, static fn(SummonCue $left, SummonCue $right): int => $left->frame <=> $right->frame);

    return $cues;
  }

  public function getCueById(string $cueId): ?SummonCue
  {
    return $this->cues[trim($cueId)] ?? null;
  }

  /**
   * @return array<string, mixed>
   */
  public function toDataArray(): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'description' => $this->description,
      'version' => $this->version,
      'linkedSummonId' => $this->linkedSummonId,
      'linkedActionId' => $this->linkedActionId,
      'tags' => $this->tags,
      'playback' => $this->playback->toArray(),
      'transitionIn' => $this->transitionIn->toArray(),
      'transitionOut' => $this->transitionOut->toArray(),
      'effectTiming' => $this->effectTiming->toArray(),
      'targetPresentation' => $this->targetPresentation->toArray(),
      'authoring' => $this->authoring,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function toTimelineArray(): array
  {
    return [
      'formatVersion' => $this->formatVersion,
      'fps' => $this->fps,
      'lengthFrames' => $this->lengthFrames,
      'tracks' => array_map(
        static fn(SummonCutsceneTrack $track): array => $track->toArray(),
        $this->getTracks(),
      ),
      'cues' => array_map(
        static fn(SummonCue $cue): array => $cue->toArray(),
        $this->getCues(),
      ),
      'editor' => $this->editor,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function toSourceArray(): array
  {
    return [
      'data' => $this->toDataArray(),
      'timeline' => $this->toTimelineArray(),
    ];
  }

  protected function normalizeOptionalString(?string $value): ?string
  {
    return $value !== null && trim($value) !== ''
      ? trim($value)
      : null;
  }
}

