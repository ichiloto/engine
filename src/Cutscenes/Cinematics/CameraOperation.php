<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Closure;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationCameraMotion;
use Ichiloto\Engine\UI\Accessibility;
use RuntimeException;

/** A non-blocking cinematic camera operation using Camera-owned math. */
final class CameraOperation implements EventPendingOperationInterface
{
  protected float $elapsed = 0.0;
  protected int $routeIndex = 0;
  protected Vector2 $start;
  protected Vector2 $base;
  protected ?Vector2 $target = null;
  protected bool $isCancelled = false;
  protected(set) bool $isComplete = false;
  protected array $route = [];
  private readonly CinematicCameraOperation $kind;
  private bool $presentationStarted = false;
  private bool $presentationSuperseded = false;
  private float $segmentEnd = 0.0;

  /** @param array<string, mixed> $command */
  public function __construct(
    protected Camera $camera,
    protected CinematicSubjectResolver $subjects,
    protected array $command,
    private readonly ?Closure $canPresentMotion = null,
  )
  {
    $name = strtolower(trim(strval($command['operation'] ?? '')));
    $this->kind = CinematicCameraOperation::tryFrom($name)
      ?? throw new RuntimeException(sprintf('Unsupported camera operation "%s".', $name ?: '(empty)'));
    $this->start = new Vector2(intval($camera->position->x), intval($camera->position->y));
    $this->base = new Vector2(intval($camera->position->x), intval($camera->position->y));
    $this->initialize();
  }

  protected function initialize(): void
  {
    $operation = $this->kind;

    if ($operation === CinematicCameraOperation::DETACH) {
      $this->camera->detach();
      $this->isComplete = true;
      return;
    }

    if (in_array($operation, [CinematicCameraOperation::ATTACH, CinematicCameraOperation::RESET], true)) {
      $this->camera->attach();
      $this->isComplete = true;
      return;
    }

    if ($operation === CinematicCameraOperation::RESTORE) {
      $this->camera->restorePrevious();
      $this->isComplete = true;
      return;
    }

    if ($operation === CinematicCameraOperation::FOCUS) {
      $this->camera->focusOn($this->resolveTarget($this->command));
      $this->isComplete = true;
      return;
    }

    // A shake displaces the view temporarily; it does not take camera ownership.
    if ($operation !== CinematicCameraOperation::SHAKE) {
      $this->camera->detach();
    }

    if ($operation === CinematicCameraOperation::ROUTE) {
      $this->route = array_values(array_filter((array) ($this->command['points'] ?? []), is_array(...)));

      if ($this->route === []) {
        throw new RuntimeException('Camera route requires at least one point.');
      }

      $this->startRoutePoint();
      return;
    }

    if ($operation !== CinematicCameraOperation::SHAKE) {
      $this->target = $this->camera->positionForFocus($this->resolveTarget($this->command));
    }

    if (Accessibility::prefersReducedMotion()) {
      $this->applyFinalState();
    } elseif ($operation === CinematicCameraOperation::PAN) {
      $this->interpolate($this->start, $this->target ?? $this->start, 0.0, $this->getDuration($this->command));
    }
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete || $this->isCancelled) {
      return true;
    }
    if (Accessibility::prefersReducedMotion()) {
      $this->camera->endTransition($this, snap: true);
      $this->applyFinalState();
      return true;
    }

    $deltaSeconds = max(0.0, $deltaSeconds);
    $operation = $this->kind;

    if ($operation === CinematicCameraOperation::TRACK) {
      $this->camera->focusOn($this->resolveTarget($this->command));
      $this->elapsed += $deltaSeconds;

      if ($this->elapsed >= $this->getDuration($this->command)) {
        $this->isComplete = true;
      }

      return $this->isComplete;
    }

    if ($operation === CinematicCameraOperation::SHAKE) {
      return $this->updateShake($deltaSeconds);
    }

    if ($operation === CinematicCameraOperation::ROUTE) {
      return $this->updateRoute($deltaSeconds);
    }

    $this->elapsed += $deltaSeconds;
    $duration = $this->getDuration($this->command);
    $progress = $duration <= 0.0 ? 1.0 : min(1.0, $this->elapsed / $duration);
    $this->interpolate($this->start, $this->target ?? $this->start, $progress, $duration);
    $this->isComplete = $progress >= 1.0;
    return $this->isComplete;
  }

  public function cancel(): void
  {
    $this->camera->endTransition($this, snap: true);
    if ($this->kind === CinematicCameraOperation::SHAKE) {
      $this->camera->moveTo(intval($this->base->x), intval($this->base->y));
    }

    $this->isCancelled = true;
    $this->isComplete = true;
  }

  protected function updateRoute(float $deltaSeconds): bool
  {
    $this->elapsed += $deltaSeconds;
    $point = $this->route[$this->routeIndex];
    $duration = $this->getDuration($point);
    $progress = $duration <= 0.0 ? 1.0 : min(1.0, $this->elapsed / $duration);
    $this->interpolate($this->start, $this->target ?? $this->start, $progress, $duration);

    if ($progress < 1.0) {
      return false;
    }

    $overflow = max(0.0, $this->elapsed - $duration);
    $this->routeIndex++;

    if ($this->routeIndex >= count($this->route)) {
      $this->isComplete = true;
      return true;
    }

    $this->startRoutePoint($overflow);
    return $overflow > 0.0 ? $this->updateRoute(0.0) : false;
  }

  protected function startRoutePoint(float $elapsed = 0.0): void
  {
    $this->elapsed = $elapsed;
    $this->start = new Vector2(intval($this->camera->position->x), intval($this->camera->position->y));
    $this->target = $this->camera->positionForFocus($this->resolveTarget($this->route[$this->routeIndex]));

    if (Accessibility::prefersReducedMotion()) {
      $last = $this->route[array_key_last($this->route)];
      $this->camera->focusOn($this->resolveTarget($last));
      $this->isComplete = true;
    } else {
      $duration = $this->getDuration($this->route[$this->routeIndex]);
      if ($duration > 0) { $this->interpolate($this->start, $this->target, min(1.0, $elapsed / $duration), $duration); }
    }
  }

  protected function updateShake(float $deltaSeconds): bool
  {
    $this->elapsed += $deltaSeconds;
    $duration = $this->getDuration($this->command);

    if ($duration <= 0.0 || $this->elapsed >= $duration || Accessibility::prefersReducedMotion()) {
      $this->camera->moveTo(intval($this->base->x), intval($this->base->y));
      $this->isComplete = true;
      return true;
    }

    $magnitude = max(1, intval($this->command['magnitude'] ?? 1));
    $phase = intval(floor($this->elapsed * 20)) % 4;
    [$x, $y] = [[-$magnitude, 0], [0, $magnitude], [$magnitude, 0], [0, -$magnitude]][$phase];
    $this->camera->moveTo(intval($this->base->x) + $x, intval($this->base->y) + $y);
    return false;
  }

  protected function applyFinalState(): void
  {
    if ($this->kind === CinematicCameraOperation::SHAKE) {
      $this->camera->moveTo(intval($this->base->x), intval($this->base->y));
    } elseif ($this->kind === CinematicCameraOperation::ROUTE) {
      $last = $this->route[array_key_last($this->route)];
      $this->camera->focusOn($this->resolveTarget($last));
    } else {
      $this->camera->moveTo(intval($this->target?->x ?? $this->start->x), intval($this->target?->y ?? $this->start->y));
    }

    $this->isComplete = true;
  }

  protected function interpolate(Vector2 $from, Vector2 $to, float $progress, float $duration): void
  {
    $position = $this->getInterpolatedPosition($from, $to, $progress);
    $supported = ($this->canPresentMotion?->__invoke() ?? false) && !Accessibility::prefersReducedMotion();
    if ($this->presentationStarted && !$this->camera->ownsTransition($this)) {
      $this->presentationStarted = false;
      $this->presentationSuperseded = $this->camera->hasTransition;
    }
    if ($this->presentationSuperseded) { return; }
    if ($supported && $progress < 1.0 && $duration > 0.0) {
      if (!$this->presentationStarted || $this->elapsed >= $this->segmentEnd) {
        if ($this->presentationStarted) {
          $this->camera->updateTransition($this, $position, 0.0);
          $this->camera->endTransition($this);
        }
        $this->segmentEnd = min($duration, $this->elapsed + PresentationCameraMotion::MAX_SECONDS);
        $this->camera->beginTransition($this, $this->getInterpolatedPosition($from, $to, $this->segmentEnd / $duration),
          $this->segmentEnd - $this->elapsed);
        $this->presentationStarted = true;
      }
      $this->camera->updateTransition($this, $position, max(0.0, $this->segmentEnd - $this->elapsed));
    } elseif ($this->presentationStarted) {
      $this->camera->updateTransition($this, $position, 0.0);
      $this->camera->endTransition($this, snap: !$supported);
      $this->presentationStarted = false;
    } else { $this->camera->moveTo(intval($position->x), intval($position->y)); }
  }

  private function getInterpolatedPosition(Vector2 $from, Vector2 $to, float $progress): Vector2
  {
    return new Vector2(round($from->x + (($to->x - $from->x) * $progress)),
      round($from->y + (($to->y - $from->y) * $progress)));
  }

  /** @param array<string, mixed> $source */
  protected function resolveTarget(array $source): Vector2
  {
    $target = is_array($source['target'] ?? null) ? $source['target'] : $source;
    return $this->subjects->position($target);
  }

  /** @param array<string, mixed> $source */
  protected function getDuration(array $source): float
  {
    $duration = $source['seconds'] ?? $source['duration'] ?? 0.0;

    if (! is_numeric($duration) || !is_finite(floatval($duration)) || floatval($duration) < 0.0) {
      throw new RuntimeException('Camera duration must be finite and nonnegative.');
    }

    return floatval($duration);
  }
}
