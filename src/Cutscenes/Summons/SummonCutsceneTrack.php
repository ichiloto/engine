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
  /** Image and field-colour frames stay uncoerced until their shared validation. */
  private ?array $authoredKeyframes = null;

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
    if (in_array($this->type, ['image', 'field-color'], true)) { $this->authoredKeyframes = []; }

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
    $keyframes = in_array($data['type'] ?? 'glyph', ['image', 'field-color'], true) ? [] : array_map(
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
    if (in_array($track->type, ['image', 'field-color'], true)) {
      if (!is_array($data['keyframes'] ?? null)) {
        throw new \InvalidArgumentException('Summon image and field-colour tracks require authored keyframes.');
      }
      $track->authoredKeyframes = $data['keyframes'];
    }
    return $track;
  }

  public function addKeyframe(SummonCutsceneKeyframe $keyframe): void
  {
    if ($this->authoredKeyframes !== null) {
      $this->authoredKeyframes[] = $this->type === 'image' ? $keyframe->toImageArray() : $keyframe->toArray();
      usort($this->authoredKeyframes, static fn(array $left, array $right): int => $left['frame'] <=> $right['frame']);
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
    if ($this->authoredKeyframes !== null) {
      return array_map($this->type === 'image' ? SummonCutsceneKeyframe::fromImageArray(...)
        : SummonCutsceneKeyframe::fromArray(...), $this->authoredKeyframes);
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
      'keyframes' => $this->authoredKeyframes ?? array_map(
        static fn(SummonCutsceneKeyframe $keyframe): array => $keyframe->toArray(),
        $this->getKeyframes(),
      ),
    ];
  }
}

