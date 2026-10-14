<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Represents one authored summon cutscene definition.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneDefinition
{
  public const array PAIRED_TIMELINE_FIELDS = ['formatVersion', 'presentations', 'editor'];

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
    public ?string $moveName = null,
    public ?SummonWielderPolicy $wielders = null,
    public string $lore = '',
    public string $element = '',
    public array $strengths = [],
    public array $weaknesses = [],
    public array $attributes = [],
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
    public ?SummonAvailability $availability = null,
    public ?int $restFrame = null,
    public array $presentations = [],
    public ?array $stage = null,
  )
  {
    $this->id = trim($id);
    $this->name = trim($name) !== '' ? trim($name) : 'New Summon';
    $this->description = trim($description);
    $this->moveName = $this->normalizeOptionalString($moveName);
    $this->lore = trim($lore);
    $this->element = trim($element);
    $this->strengths = array_values(array_filter(array_map('strval', $strengths), static fn(string $entry): bool => trim($entry) !== ''));
    $this->weaknesses = array_values(array_filter(array_map('strval', $weaknesses), static fn(string $entry): bool => trim($entry) !== ''));
    $this->attributes = $attributes;
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
    if (array_key_exists('stage', $timeline) && !is_array($timeline['stage'])) {
      throw new \InvalidArgumentException('Summon cinematic stage must be a descriptor.');
    }
    if (array_key_exists('presentations', $timeline) && (!is_array($timeline['presentations'])
      || count($timeline['presentations']) !== 2
      || array_diff(array_keys($timeline['presentations']), ['terminal', 'graphical']) !== []
      || !is_array($timeline['presentations']['terminal'] ?? null)
      || !is_array($timeline['presentations']['graphical'] ?? null)
      || array_diff(array_keys($timeline), self::PAIRED_TIMELINE_FIELDS) !== [])) {
      throw new \InvalidArgumentException('Summon presentation sequences cannot be mixed with a shared timeline.');
    }
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
      isset($data['moveName']) ? strval($data['moveName']) : null,
      array_key_exists('wielders', $data)
        ? SummonWielderPolicy::fromAuthored($data['wielders'])
        : null,
      strval($data['lore'] ?? ''),
      strval($data['element'] ?? ''),
      is_array($data['strengths'] ?? null) ? $data['strengths'] : [],
      is_array($data['weaknesses'] ?? null) ? $data['weaknesses'] : [],
      is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
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
      array_key_exists('availability', $data)
        ? SummonAvailability::fromAuthored($data['availability'])
        : null,
      isset($timeline['restFrame']) ? intval($timeline['restFrame']) : null,
      is_array($timeline['presentations'] ?? null) ? $timeline['presentations'] : [],
      $timeline['stage'] ?? null,
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
    $data = [
      'id' => $this->id,
      'name' => $this->name,
      'description' => $this->description,
      'moveName' => $this->moveName,
      'lore' => $this->lore,
      'element' => $this->element,
      'strengths' => $this->strengths,
      'weaknesses' => $this->weaknesses,
      'attributes' => $this->attributes,
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

    if ($this->availability !== null) {
      $data['availability'] = $this->availability->toArray();
    }

    if ($this->wielders !== null) {
      $data['wielders'] = $this->wielders->toArray();
    }

    return $data;
  }

  /**
   * @return array<string, mixed>
   */
  public function toTimelineArray(): array
  {
    if ($this->presentations !== []) {
      return ['formatVersion' => $this->formatVersion, 'presentations' => $this->presentations, 'editor' => $this->editor];
    }
    return [
      'formatVersion' => $this->formatVersion,
      'fps' => $this->fps,
      'lengthFrames' => $this->lengthFrames,
      ...($this->restFrame === null ? [] : ['restFrame' => $this->restFrame]),
      ...($this->stage === null ? [] : ['stage' => $this->stage]),
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

  /**
   * Determines whether this definition is currently available in the world.
   *
   * Omitting availability preserves the historical open-summon behavior.
   */
  public function isAvailable(\Ichiloto\Engine\Core\GameState $gameState, ?\Ichiloto\Engine\Entities\Party $party = null): bool
  {
    return $this->availability === null || $this->availability->isSatisfied($gameState, $party);
  }

  protected function normalizeOptionalString(?string $value): ?string
  {
    return $value !== null && trim($value) !== ''
      ? trim($value)
      : null;
  }
}
