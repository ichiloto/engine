<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/** One retained transition towards the viewport's declared logical world origin. */
final readonly class PresentationCameraMotion
{
  public const float MAX_SECONDS = 60.0;

  public function __construct(
    public string $id,
    public float $seconds,
    public CameraMotionEasing $easing = CameraMotionEasing::SMOOTHSTEP,
  ) {
    if ($id === '' || strlen($id) > 256 || preg_match('//u', $id) !== 1 || preg_match('/\p{Cc}/u', $id) === 1) {
      throw new InvalidArgumentException('A camera transition requires a nonempty control-free UTF-8 ID of at most 256 bytes.');
    }
    if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > self::MAX_SECONDS) {
      throw new InvalidArgumentException('A camera transition lasts more than zero and at most 60 seconds.');
    }
  }

  /** @return array{id: string, duration: float, easing: string} */
  public function toArray(): array
  {
    return ['id' => $this->id, 'duration' => $this->seconds, 'easing' => $this->easing->value];
  }
}
