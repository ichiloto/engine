<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * Represents one track in the authored summon cutscene timeline.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutsceneTrack
{
  /**
   * @var SummonCutsceneKeyframe[]
   */
  protected array $keyframes = [];

  /**
   * @param SummonCutsceneKeyframe[] $keyframes
   */
  public function __construct(
    public string $type,
    public string $id,
    array $keyframes = [],
    public string $presentation = 'all',
  )
  {
    $this->type = trim($type) !== '' ? trim($type) : 'glyph';
    $this->id = trim($id);
    $this->presentation = EffectPresentation::validateTrack($presentation);

    foreach ($keyframes as $keyframe) {
      if ($keyframe instanceof SummonCutsceneKeyframe) {
        $this->addKeyframe($keyframe);
      }
    }
  }

  /**
   * @param array<string, mixed> $data
   * @return self
   */
  public static function fromArray(array $data): self
  {
    $keyframes = array_map(
      static fn(array $keyframe): SummonCutsceneKeyframe => SummonCutsceneKeyframe::fromArray($keyframe),
      array_values(array_filter($data['keyframes'] ?? [], 'is_array'))
    );

    return new self(
      strval($data['type'] ?? 'glyph'),
      strval($data['id'] ?? ''),
      $keyframes,
      EffectPresentation::validateTrack($data['presentation'] ?? 'all'),
    );
  }

  public function addKeyframe(SummonCutsceneKeyframe $keyframe): void
  {
    $this->keyframes[] = $keyframe;
    usort(
      $this->keyframes,
      static fn(SummonCutsceneKeyframe $left, SummonCutsceneKeyframe $right): int => $left->frame <=> $right->frame,
    );
  }

  /**
   * @return SummonCutsceneKeyframe[]
   */
  public function getKeyframes(): array
  {
    return array_values($this->keyframes);
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'type' => $this->type,
      'id' => $this->id,
      ...($this->presentation === 'all' ? [] : ['presentation' => $this->presentation]),
      'keyframes' => array_map(
        static fn(SummonCutsceneKeyframe $keyframe): array => $keyframe->toArray(),
        $this->getKeyframes(),
      ),
    ];
  }
}

