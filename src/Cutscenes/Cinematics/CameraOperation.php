<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Rendering\Camera;
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

  /** @param array<string, mixed> $command */
  public function __construct(
    protected Camera $camera,
    protected CinematicSubjectResolver $subjects,
    protected array $command,
  )
  {
    $this->start = new Vector2(intval($camera->position->x), intval($camera->position->y));
    $this->base = new Vector2(intval($camera->position->x), intval($camera->position->y));
    $this->initialize();
  }

  protected function initialize(): void
  {
    $operation = $this->operation();

    if (! in_array($operation, CinematicCommandSchema::CAMERA_OPERATIONS, true)) {
      throw new RuntimeException(sprintf('Unsupported camera operation "%s".', $operation ?: '(empty)'));
    }

    if ($operation === 'detach') {
      $this->camera->detach();
      $this->isComplete = true;
      return;
    }

    if (in_array($operation, ['attach', 'reset'], true)) {
      $this->camera->attach();
      $this->isComplete = true;
      return;
    }

    if ($operation === 'restore') {
      $this->camera->restorePrevious();
      $this->isComplete = true;
      return;
    }

    if ($operation === 'focus') {
      $this->camera->focusOn($this->resolveTarget($this->command));
      $this->isComplete = true;
      return;
    }

    $this->camera->detach();

    if ($operation === 'route') {
      $this->route = array_values(array_filter((array) ($this->command['points'] ?? []), is_array(...)));

      if ($this->route === []) {
        throw new RuntimeException('Camera route requires at least one point.');
      }

      $this->startRoutePoint();
      return;
    }

    if ($operation !== 'shake') {
      $this->target = $this->camera->positionForFocus($this->resolveTarget($this->command));
    }

    if (Accessibility::prefersReducedMotion()) {
      $this->applyFinalState();
    }
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete || $this->isCancelled) {
      return true;
    }

    $deltaSeconds = max(0.0, $deltaSeconds);
    $operation = $this->operation();

    if ($operation === 'track') {
      $this->camera->focusOn($this->resolveTarget($this->command));
      $this->elapsed += $deltaSeconds;

      if ($this->elapsed >= $this->duration($this->command)) {
        $this->isComplete = true;
      }

      return $this->isComplete;
    }

    if ($operation === 'shake') {
      return $this->updateShake($deltaSeconds);
    }

    if ($operation === 'route') {
      return $this->updateRoute($deltaSeconds);
    }

    $this->elapsed += $deltaSeconds;
    $duration = $this->duration($this->command);
    $progress = $duration <= 0.0 ? 1.0 : min(1.0, $this->elapsed / $duration);
    $this->interpolate($this->start, $this->target ?? $this->start, $progress);
    $this->isComplete = $progress >= 1.0;
    return $this->isComplete;
  }

  public function cancel(): void
  {
    if ($this->operation() === 'shake') {
      $this->camera->moveTo(intval($this->base->x), intval($this->base->y));
    }

    $this->isCancelled = true;
    $this->isComplete = true;
  }

  protected function updateRoute(float $deltaSeconds): bool
  {
    $this->elapsed += $deltaSeconds;
    $point = $this->route[$this->routeIndex];
    $duration = $this->duration($point);
    $progress = $duration <= 0.0 ? 1.0 : min(1.0, $this->elapsed / $duration);
    $this->interpolate($this->start, $this->target ?? $this->start, $progress);

    if ($progress < 1.0) {
      return false;
    }

    $overflow = max(0.0, $this->elapsed - $duration);
    $this->routeIndex++;

    if ($this->routeIndex >= count($this->route)) {
      $this->isComplete = true;
      return true;
    }

    $this->startRoutePoint();
    return $overflow > 0.0 ? $this->updateRoute($overflow) : false;
  }

  protected function startRoutePoint(): void
  {
    $this->elapsed = 0.0;
    $this->start = new Vector2(intval($this->camera->position->x), intval($this->camera->position->y));
    $this->target = $this->camera->positionForFocus($this->resolveTarget($this->route[$this->routeIndex]));

    if (Accessibility::prefersReducedMotion()) {
      $last = $this->route[array_key_last($this->route)];
      $this->camera->focusOn($this->resolveTarget($last));
      $this->isComplete = true;
    }
  }

  protected function updateShake(float $deltaSeconds): bool
  {
    $this->elapsed += $deltaSeconds;
    $duration = $this->duration($this->command);

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
    if ($this->operation() === 'shake') {
      $this->camera->moveTo(intval($this->base->x), intval($this->base->y));
    } elseif ($this->operation() === 'route') {
      $last = $this->route[array_key_last($this->route)];
      $this->camera->focusOn($this->resolveTarget($last));
    } else {
      $this->camera->moveTo(intval($this->target?->x ?? $this->start->x), intval($this->target?->y ?? $this->start->y));
    }

    $this->isComplete = true;
  }

  protected function interpolate(Vector2 $from, Vector2 $to, float $progress): void
  {
    $this->camera->moveTo(
      intval(round($from->x + (($to->x - $from->x) * $progress))),
      intval(round($from->y + (($to->y - $from->y) * $progress))),
    );
  }

  /** @param array<string, mixed> $source */
  protected function resolveTarget(array $source): Vector2
  {
    $target = is_array($source['target'] ?? null) ? $source['target'] : $source;
    return $this->subjects->position($target);
  }

  /** @param array<string, mixed> $source */
  protected function duration(array $source): float
  {
    $duration = $source['seconds'] ?? $source['duration'] ?? 0.0;

    if (! is_numeric($duration) || floatval($duration) < 0.0) {
      throw new RuntimeException('Camera duration must be zero or a positive number.');
    }

    return floatval($duration);
  }

  protected function operation(): string
  {
    return strtolower(trim(strval($this->command['operation'] ?? '')));
  }
}
