<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\UI\Accessibility;

/** A cinematic lane owns one shared effect session, never a manager-wide slot. */
final class FieldAnimationOperation implements EventPendingOperationInterface
{
  protected FieldEffectSession $session;
  protected(set) bool $isComplete = false;

  public function __construct(
    Animation|CompiledEffectTimeline $animation,
    protected CinematicPresentationManager $presentation,
    protected Vector2 $position,
    protected bool $screenSpace = false,
    ?float $secondsPerFrame = null,
  )
  {
    if ($animation instanceof Animation) {
      $timeline = LegacyAnimationTimeline::compile($animation, includeFlash: false);
      // The field's rest treatment is its first frame, not the battle's final frame.
      $timeline->defaults['restFrame'] = 0;
      $secondsPerFrame ??= .12;
    } else {
      $timeline = $animation;
      if ($secondsPerFrame !== null) {
        throw new \InvalidArgumentException('An effect timeline owns its frame rate.');
      }
    }
    if ($timeline->defaults['playback']['loop'] ?? false) {
      throw new \InvalidArgumentException('A blocking cinematic field effect must play once; loops belong to the map.');
    }
    $this->session = new FieldEffectSession('cinematic-effect:' . spl_object_id($this),
      FieldEffectAnchor::createAtPosition($position), $timeline, $secondsPerFrame);
    $this->presentation->presentEffect($this->session, $screenSpace);
    FieldEffectSession::playCues($this->session->playback->takeCurrentFrameCues());
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete) {
      return true;
    }

    // Host cleanup (transfer/shutdown) cancels this owner instead of letting it reappear.
    if (!$this->presentation->hasEffect($this->session->id)) { $this->cancel(); return true; }
    $update = $this->session->update($deltaSeconds, Accessibility::prefersReducedMotion());
    FieldEffectSession::playCues($update->crossedCues);

    if ($this->session->playback->isCompleted) {
      $this->presentation->removeEffect($this->session->id);
      $this->isComplete = true;
      return true;
    }

    return false;
  }

  public function cancel(): void
  {
    $this->session->playback->pause();
    $this->presentation->removeEffect($this->session->id);
    $this->isComplete = true;
  }

}
