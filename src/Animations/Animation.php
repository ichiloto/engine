<?php

namespace Ichiloto\Engine\Animations;

/**
 * Represents a terminal animation made up of key frames.
 *
 * @package Ichiloto\Engine\Animations
 */
final class Animation
{
  /**
   * @var array<int, AnimationFrame> $frames
   */
  protected array $frames = [];
  /**
   * @var array<int, AnimationCue> $cues
   */
  protected array $cues = [];

  /**
   * @param int $id The animation id.
   * @param string $name The animation name.
   * @param AnimationTargetPosition $position The target anchor.
   * @param int $maxFrames The number of key frames.
   * @param AnimationFrame[] $frames The frame payloads.
   * @param array<int, AnimationCue> $cues The per-frame sound/flash cues.
   */
  public function __construct(
    public int $id,
    public string $name,
    public AnimationTargetPosition $position = AnimationTargetPosition::CENTER,
    public int $maxFrames = 1,
    array $frames = [],
    array $cues = [],
  )
  {
    $this->maxFrames = max(1, $maxFrames);

    foreach ($frames as $frame) {
      if ($frame instanceof AnimationFrame) {
        $this->frames[$frame->index] = $frame;
      }
    }

    foreach ($cues as $frameIndex => $cue) {
      if ($cue instanceof AnimationCue) {
        $this->cues[max(1, intval($frameIndex))] = $cue;
      }
    }

    $this->ensureFrameCount($this->maxFrames);
  }

  /**
   * Hydrates an animation from an array payload.
   *
   * @param array<string, mixed> $data The serialized animation payload.
   * @return self
   */
  public static function fromArray(array $data): self
  {
    $frames = array_map(
      static fn(array $frame): AnimationFrame => AnimationFrame::fromArray($frame),
      array_values(array_filter($data['frames'] ?? [], 'is_array'))
    );
    $cues = [];

    foreach (array_values(array_filter($data['cues'] ?? [], 'is_array')) as $cueData) {
      $frameIndex = max(1, intval($cueData['frame'] ?? 1));
      $cues[$frameIndex] = AnimationCue::fromArray($cueData);
    }

    return new self(
      intval($data['id'] ?? 0),
      strval($data['name'] ?? 'New Animation'),
      AnimationTargetPosition::fromValue(isset($data['position']) ? strval($data['position']) : null),
      intval($data['maxFrames'] ?? max(1, count($frames))),
      $frames,
      $cues,
    );
  }

  /**
   * Returns all frames in display order.
   *
   * @return AnimationFrame[]
   */
  public function getFrames(): array
  {
    $frames = $this->frames;
    ksort($frames);

    return array_values($frames);
  }

  /**
   * Returns the requested frame, creating a blank one when missing.
   *
   * @param int $frameIndex The 1-based frame index.
   * @return AnimationFrame
   */
  public function getFrame(int $frameIndex): AnimationFrame
  {
    $frameIndex = max(1, min($this->maxFrames, $frameIndex));

    if (! isset($this->frames[$frameIndex])) {
      $this->frames[$frameIndex] = new AnimationFrame($frameIndex);
    }

    return $this->frames[$frameIndex];
  }

  /**
   * Replaces the frame at the given index.
   *
   * @param AnimationFrame $frame The replacement frame.
   * @return void
   */
  public function setFrame(AnimationFrame $frame): void
  {
    $frame->index = max(1, min($this->maxFrames, $frame->index));
    $this->frames[$frame->index] = $frame;
  }

  /**
   * Ensures that frame access remains within the requested frame count.
   *
   * @param int $maxFrames The desired frame count.
   * @return void
   */
  public function ensureFrameCount(int $maxFrames): void
  {
    $this->maxFrames = max(1, $maxFrames);

    foreach (array_keys($this->frames) as $frameIndex) {
      if ($frameIndex > $this->maxFrames) {
        unset($this->frames[$frameIndex]);
      }
    }

    foreach (array_keys($this->cues) as $frameIndex) {
      if ($frameIndex > $this->maxFrames) {
        unset($this->cues[$frameIndex]);
      }
    }

    for ($frameIndex = 1; $frameIndex <= $this->maxFrames; $frameIndex++) {
      if (! isset($this->frames[$frameIndex])) {
        $this->frames[$frameIndex] = new AnimationFrame($frameIndex);
      }
    }
  }

  /**
   * Updates one cell on the requested frame.
   *
   * @param int $frameIndex The frame index.
   * @param int $x The horizontal offset.
   * @param int $y The vertical offset.
   * @param string $symbol The symbol to store.
   * @param string|null $color The optional color name.
   * @return void
   */
  public function setCell(int $frameIndex, int $x, int $y, string $symbol, ?string $color = null): void
  {
    $this->getFrame($frameIndex)->setCell($x, $y, $symbol, $color);
  }

  /**
   * Returns the cue for the given frame, if one exists.
   *
   * @param int $frameIndex The frame index.
   * @return AnimationCue|null
   */
  public function getCue(int $frameIndex): ?AnimationCue
  {
    return $this->cues[max(1, $frameIndex)] ?? null;
  }

  /**
   * Sets the cue for a frame, removing it when empty.
   *
   * @param int $frameIndex The frame index.
   * @param AnimationCue $cue The cue payload.
   * @return void
   */
  public function setCue(int $frameIndex, AnimationCue $cue): void
  {
    $frameIndex = max(1, min($this->maxFrames, $frameIndex));

    if ($cue->isEmpty()) {
      unset($this->cues[$frameIndex]);
      return;
    }

    $this->cues[$frameIndex] = $cue;
  }

  /**
   * Returns the serialized animation payload.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'position' => $this->position->value,
      'maxFrames' => $this->maxFrames,
      'frames' => array_map(
        static fn(AnimationFrame $frame): array => $frame->toArray(),
        $this->getFrames()
      ),
      'cues' => array_map(
        static fn(int $frameIndex, AnimationCue $cue): array => ['frame' => $frameIndex, ...$cue->toArray()],
        array_keys($this->cues),
        array_values($this->cues)
      ),
    ];
  }
}
