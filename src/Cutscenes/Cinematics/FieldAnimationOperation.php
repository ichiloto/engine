<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationPlaybackSession;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\UI\Accessibility;

/** Non-blocking field host for an existing reusable Animation. */
final class FieldAnimationOperation implements EventPendingOperationInterface
{
  protected AnimationPlaybackSession $session;
  protected(set) bool $isComplete = false;

  public function __construct(
    Animation $animation,
    protected CinematicPresentationManager $presentation,
    protected Vector2 $position,
    protected bool $screenSpace = false,
    float $secondsPerFrame = 0.12,
  )
  {
    $this->session = new AnimationPlaybackSession($animation, max(0.01, $secondsPerFrame));

    if (Accessibility::prefersReducedMotion()) {
      $this->session->cancel();
      $this->presentation->clearAnimation();
      $this->isComplete = true;
      return;
    }

    $this->playCue($this->session->currentFrame);
    $this->showCurrentFrame();
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete) {
      return true;
    }

    $frames = $this->session->update($deltaSeconds);

    foreach ($frames as $frame) {
      $this->playCue($frame);
    }

    if ($this->session->isComplete) {
      $this->presentation->clearAnimation();
      $this->isComplete = true;
      return true;
    }

    $this->showCurrentFrame();
    return false;
  }

  public function cancel(): void
  {
    $this->session->cancel();
    $this->presentation->clearAnimation();
    $this->isComplete = true;
  }

  protected function showCurrentFrame(): void
  {
    $this->presentation->showAnimationFrame(
      $this->session->animation->getFrame($this->session->currentFrame),
      $this->position,
      $this->screenSpace,
    );
  }

  protected function playCue(int $frame): void
  {
    $cue = $this->session->animation->getCue($frame);

    if ($cue !== null && $cue->soundEffect !== '') {
      play_sound($cue->soundEffect);
    }
  }
}
