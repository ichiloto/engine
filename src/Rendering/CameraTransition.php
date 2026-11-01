<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\CameraMotionEasing;
use Ichiloto\Engine\Rendering\Presentation\PresentationCameraMotion;
use InvalidArgumentException;

/** PHP-owned timing for one retained camera destination; never serialized as game state. */
final class CameraTransition
{
  private readonly Vector2 $target;
  public Vector2 $targetPosition { get => clone $this->target; }
  public private(set) float $remainingSeconds;

  public function __construct(
    public readonly object $owner,
    Vector2 $targetPosition,
    public readonly float $seconds,
    public readonly CameraMotionEasing $easing,
  ) {
    if (!is_finite($seconds) || $seconds <= 0 || $seconds > PresentationCameraMotion::MAX_SECONDS) {
      throw new InvalidArgumentException('A retained camera segment lasts more than zero and at most 60 seconds.');
    }
    $this->target = clone $targetPosition;
    $this->remainingSeconds = $seconds;
  }

  public function setRemainingSeconds(float $seconds): void
  {
    if (!is_finite($seconds) || $seconds < 0 || $seconds > $this->seconds) {
      throw new InvalidArgumentException('Remaining camera time must lie within its segment duration.');
    }
    $this->remainingSeconds = $seconds;
  }
}
