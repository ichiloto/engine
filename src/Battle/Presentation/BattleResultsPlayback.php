<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Presentation clock only. Outcomes are already committed; no character is read or written. */
final class BattleResultsPlayback
{
  private const REARM = 0.08;
  private const TRANSITION = 0.32;
  private const EXIT = 0.42;
  private const BUTTON_FADE_OUT = 0.12;
  private const BUTTON_GAP = 0.06;
  private const BUTTON_FADE_IN = 0.14;
  /** @var list<array{kind: string, actor: ?int, detail: ?int}> */
  private array $stages = [['kind' => 'primary', 'actor' => null, 'detail' => null]];
  private int $stageIndex = 0;
  private float $elapsed = 0;
  private float $clock = 0;
  private float $lastConfirm = -INF;
  private float $transitionRemaining = 0;
  private ?float $exitRemaining = null;
  private ?float $confirmationChangedAt = null;
  private ?int $scrollLimit = null;
  public private(set) int $scrollOffset = 0;

  public function __construct(public readonly BattleRewards $rewards, public readonly bool $reducedMotion = false)
  {
    foreach ($rewards->progression as $index => $award) {
      if ($award->after->actorId !== $award->before->actorId) {
        throw new InvalidArgumentException('Results progression snapshots must describe the same actor.');
      }
      if ($award->after->level > $award->before->level) {
        $this->stages[] = ['kind' => 'level', 'actor' => $index, 'detail' => null];
      }
      foreach ($this->abilities($index) as $detail => $_) {
        $this->stages[] = ['kind' => 'ability', 'actor' => $index, 'detail' => $detail];
      }
    }
    foreach ($rewards->specialRewards as $detail => $_) {
      $this->stages[] = ['kind' => 'special', 'actor' => null, 'detail' => $detail];
    }
    if ($reducedMotion) { $this->elapsed = $this->duration(); }
  }

  public function update(float $delta): void
  {
    if (!is_finite($delta) || $delta < 0) {
      throw new InvalidArgumentException('Results delta must be finite and nonnegative.');
    }
    $this->clock += $delta;
    if ($this->exitRemaining !== null) {
      $this->exitRemaining = max(0, $this->exitRemaining - $delta);
      return;
    }
    $held = min($delta, $this->transitionRemaining);
    $this->transitionRemaining -= $held;
    $remaining = $this->duration() - $this->elapsed;
    $activeDelta = $delta - $held;
    $this->elapsed = min($this->duration(), $this->elapsed + $activeDelta);
    if ($remaining > 0 && $activeDelta >= $remaining) {
      // Preserve the actual completion boundary even when a frame crosses it.
      $this->confirmationChangedAt = $this->clock - ($activeDelta - $remaining);
    }
  }

  /** A semantic press: callers must reject native repeats where that information exists. */
  public function confirm(): bool
  {
    if ($this->isFinished()) { return true; }
    if ($this->exitRemaining !== null) { return false; }
    // Refresh on every attempted repeat, rather than letting a held stream advance every 80ms.
    $armed = $this->clock - $this->lastConfirm >= self::REARM;
    $this->lastConfirm = $this->clock;
    if (!$armed || !$this->confirmation()['enabled']) { return false; }
    if (!$this->isComplete()) {
      $this->elapsed = $this->duration();
      $this->confirmationChangedAt = $this->clock;
      return false;
    }
    if ($this->stageIndex + 1 < count($this->stages)) {
      $this->stageIndex++;
      $this->scrollOffset = 0;
      $this->scrollLimit = null;
      $this->elapsed = $this->reducedMotion ? $this->duration() : 0;
      $this->transitionRemaining = $this->reducedMotion ? 0 : self::TRANSITION;
      $this->confirmationChangedAt = null;
    } else {
      $this->exitRemaining = $this->reducedMotion ? 0 : self::EXIT;
    }
    return $this->isFinished();
  }

  public function navigate(int $direction): void
  {
    if ($this->exitRemaining !== null || $this->transitionRemaining > 0 || !$this->isComplete()) { return; }
    $this->scrollOffset = max(0, min($this->pageCount() - 1, $this->scrollOffset + ($direction <=> 0)));
  }

  /** @return array{kind: string, actor: ?int, detail: ?int} */
  public function currentStage(): array { return $this->stages[$this->stageIndex]; }
  public function isComplete(): bool { return $this->elapsed >= $this->duration() && $this->transitionRemaining === 0.0; }
  public function isFinished(): bool { return $this->exitRemaining === 0.0; }
  public function time(): float { return $this->clock; }
  public function stageTime(): float { return $this->elapsed; }
  public function isExiting(): bool { return $this->exitRemaining !== null; }

  /**
   * The whole action changes together; input cannot overtake its visible label.
   * @return array{label: string, opacity: float, enabled: bool}
   */
  public function confirmation(): array
  {
    $label = $this->isComplete() ? 'Continue' : 'Complete';
    $opacity = 1.0;
    $changing = false;
    if (!$this->reducedMotion && $this->confirmationChangedAt !== null) {
      $time = $this->clock - $this->confirmationChangedAt;
      $incomingAt = self::BUTTON_FADE_OUT + self::BUTTON_GAP;
      $changing = $time < $incomingAt + self::BUTTON_FADE_IN;
      if ($time < self::BUTTON_FADE_OUT) {
        $label = 'Complete';
        $opacity = max(0.0, 1 - $time / self::BUTTON_FADE_OUT);
      } else {
        $opacity = max(0.0, min(1.0, ($time - $incomingAt) / self::BUTTON_FADE_IN));
      }
    }
    return ['label' => $label, 'opacity' => $opacity,
      'enabled' => !$changing && !$this->isExiting() && $this->transitionRemaining === 0.0];
  }

  public function pageCount(): int { return ($this->scrollLimit ?? (BattleResultsContent::pageCount($this) - 1)) + 1; }
  /** Each presentation declares its own page geometry, without changing stage or reward facts. */
  public function setScrollLimit(int $maxOffset): void
  {
    if ($maxOffset < 0) { throw new InvalidArgumentException('Results scroll limit cannot be negative.'); }
    $this->scrollLimit = $maxOffset;
    $this->scrollOffset = min($this->scrollOffset, $maxOffset);
  }

  /** Counts character ability groups once, while preserving their inner detail index. */
  public function eventCounter(): array
  {
    $groups = [];
    foreach ($this->stages as $index => $stage) {
      if ($stage['kind'] === 'primary') { continue; }
      $key = $stage['kind'] . ':' . ($stage['actor'] ?? $stage['detail']);
      $groups[$key] ??= count($groups) + 1;
      if ($index === $this->stageIndex) { $current = $groups[$key]; }
    }
    return ['current' => $current ?? 0, 'total' => count($groups)];
  }

  /** Existing facts only, detached from live characters at the award boundary. */
  public function abilities(int $actor): array
  {
    return $this->rewards->progression[$actor]->learnedDetails;
  }

  public function opacity(): float
  {
    if ($this->exitRemaining !== null) { return $this->exitRemaining / self::EXIT; }
    if ($this->reducedMotion) { return 1; }
    return min(1, max(0, ($this->elapsed - ($this->stageIndex === 0 ? 0.18 : 0)) / 0.32));
  }

  public function reveal(float $delay = 0, float $duration = 0.4): float
  {
    return $this->reducedMotion || $this->isComplete() ? 1 : max(0, min(1, ($this->elapsed - $delay) / $duration));
  }

  /** @return array{level: int, current: int, needed: int, ratio: float, maximum: bool, experience: int} */
  public function progress(int $actor): array
  {
    $award = $this->rewards->progression[$actor];
    $before = $award->before;
    $after = $award->after;
    assert($before !== null && $after !== null);
    if ($this->currentStage()['kind'] !== 'primary' || $this->reducedMotion || $this->isComplete()) {
      return [...$after->progressAt($after->experience), 'experience' => $after->experience];
    }
    $thresholds = array_values(array_filter($after->thresholds,
      static fn(int $value, int $level): bool => $level <= $after->maxLevel
        && $value > $before->experience && $value <= $after->experience, ARRAY_FILTER_USE_BOTH));
    $points = [$before->experience, ...$thresholds, $after->experience];
    $segments = count($points) - 1;
    $position = $this->reveal(0.9, 1.3) * $segments;
    $segment = min($segments - 1, (int)$position);
    $fraction = min(1.0, ($position - $segment) / 0.85);
    $experience = (int)floor($points[$segment] + ($points[$segment + 1] - $points[$segment]) * $fraction);
    $progress = $after->progressAt($experience);
    // Hold a full gauge at each crossed threshold before the next segment resets it.
    if ($segment < count($thresholds) && $fraction === 1.0) {
      $progress = $after->progressAt(max(0, $points[$segment + 1] - 1));
      $progress['current'] = $progress['needed'];
      $progress['ratio'] = 1.0;
    }
    return [...$progress, 'experience' => $experience];
  }

  private function duration(): float { return $this->currentStage()['kind'] === 'primary' ? 2.25 : 1.0; }
}
