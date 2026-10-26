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
  /** Authored presentation fields are validated by the shared timeline boundary. */
  private array $presentationFields = [];
  /** Image frames stay uncoerced until shared geometry/range validation. */
  private ?array $imageKeyframes = null;

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
    if ($this->type === 'image') { $this->imageKeyframes = []; }

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
    $keyframes = ($data['type'] ?? 'glyph') === 'image' ? [] : array_map(
      static fn(array $keyframe): SummonCutsceneKeyframe => SummonCutsceneKeyframe::fromArray($keyframe),
      array_values(array_filter($data['keyframes'] ?? [], 'is_array'))
    );

    $track = new self(
      strval($data['type'] ?? 'glyph'),
      strval($data['id'] ?? ''),
      $keyframes,
      EffectPresentation::validateTrack($data['presentation'] ?? 'all'),
    );
    $track->presentationFields = array_diff_key($data, array_flip(['type', 'id', 'presentation', 'keyframes']));
    if ($track->type === 'image') {
      if (!is_array($data['keyframes'] ?? null)) {
        throw new \InvalidArgumentException('Summon image tracks require authored keyframes.');
      }
      $track->imageKeyframes = $data['keyframes'];
    }
    return $track;
  }

  public function addKeyframe(SummonCutsceneKeyframe $keyframe): void
  {
    if ($this->imageKeyframes !== null) {
      $this->imageKeyframes[] = $keyframe->toImageArray();
      usort($this->imageKeyframes, static fn(array $left, array $right): int => $left['frame'] <=> $right['frame']);
      return;
    }
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
    if ($this->imageKeyframes !== null) {
      return array_map(SummonCutsceneKeyframe::fromImageArray(...), $this->imageKeyframes);
    }
    return array_values($this->keyframes);
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      ...$this->presentationFields,
      'type' => $this->type,
      'id' => $this->id,
      ...($this->presentation === 'all' ? [] : ['presentation' => $this->presentation]),
      'keyframes' => $this->imageKeyframes ?? array_map(
        static fn(SummonCutsceneKeyframe $keyframe): array => $keyframe->toArray(),
        $this->getKeyframes(),
      ),
    ];
  }
}

