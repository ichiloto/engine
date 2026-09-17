<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;

/** Local focus and motion only. The pause state executes the returned action once. */
final class BattlePauseMenu
{
  public const string WARNING = 'Unsaved progress will be lost.';
  public private(set) int $selection = 0;
  public private(set) ?PauseAction $confirmation = null;
  private ?PauseAction $pending = null;
  private ?PauseAction $nextView = null;
  private string $phase = 'closed';
  private float $started = 0;
  private int $rootSelection = 0;
  private Closure $clock;

  public function __construct(public bool $reducedMotion = false, ?Closure $clock = null)
  {
    $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
  }

  public function open(int $selection = 0): void
  {
    $this->selection = $this->rootSelection = max(0, min(3, $selection));
    $this->confirmation = $this->pending = $this->nextView = null;
    $this->begin('enter');
  }

  public function close(): void
  {
    $this->phase = 'closed';
    $this->pending = $this->nextView = null;
  }

  public function time(): float { return ($this->clock)(); }
  public function isReady(): bool { return $this->phase === 'idle'; }
  public function isClosed(): bool { return $this->phase === 'closed'; }
  public function isPressed(): bool { return $this->phase === 'exit' || $this->phase === 'out'; }

  public function tick(): ?PauseAction
  {
    $elapsed = $this->time() - $this->started;
    if ($this->phase === 'enter' && $elapsed >= 0.16
      || $this->phase === 'in' && $elapsed >= 0.07) {
      $this->phase = 'idle';
    } elseif ($this->phase === 'out' && $elapsed >= 0.07) {
      $this->confirmation = $this->nextView;
      $this->selection = $this->confirmation === null ? $this->rootSelection : 0;
      $this->nextView = null;
      $this->begin('in');
    } elseif ($this->phase === 'exit' && $elapsed >= 0.12) {
      $action = $this->pending;
      $this->close();
      return $action;
    }
    return null;
  }

  /** @return list<string> */
  public function labels(): array
  {
    return $this->confirmation === null
      ? array_map(static fn(PauseAction $action): string => $action->value, PauseAction::cases())
      : ['Cancel', $this->confirmation->value];
  }

  public function heading(): string
  {
    return match ($this->confirmation) {
      PauseAction::TITLE => 'Return to title?', PauseAction::EXIT => 'Exit the game?', default => 'PAUSED',
    };
  }

  public function navigate(int $step): void
  {
    if ($this->isReady()) { $this->selection = max(0, min(count($this->labels()) - 1, $this->selection + $step)); }
  }

  public function back(bool $pauseShortcut = false): void
  {
    if (!$this->isReady()) { return; }
    if ($this->confirmation === null) { $this->leave(PauseAction::RESUME); }
    elseif (!$pauseShortcut) { $this->nextView = null; $this->begin('out'); }
  }

  public function confirm(): void
  {
    if (!$this->isReady()) { return; }
    if ($this->confirmation !== null) {
      if ($this->selection === 0) { $this->back(); }
      else { $this->leave($this->confirmation); }
      return;
    }
    $action = PauseAction::cases()[$this->selection];
    if ($action === PauseAction::TITLE || $action === PauseAction::EXIT) {
      $this->rootSelection = $this->selection;
      $this->nextView = $action;
      $this->begin('out');
    } else { $this->leave($action); }
  }

  public function opacity(): float
  {
    $elapsed = max(0, $this->time() - $this->started);
    return match ($this->phase) {
      'closed' => 0.0, 'enter' => min(1, $elapsed / 0.16), 'in' => min(1, $elapsed / 0.07),
      'out' => max(0, 1 - $elapsed / 0.07), 'exit' => max(0, 1 - $elapsed / 0.12), default => 1.0,
    };
  }

  public function scale(): float
  {
    if ($this->reducedMotion) { return 1.0; }
    return match ($this->phase) {
      'enter' => 1 - 0.02 * (1 - $this->opacity()) ** 3,
      'exit' => 0.99 + 0.01 * $this->opacity(), default => 1.0,
    };
  }

  private function leave(PauseAction $action): void { $this->pending = $action; $this->begin('exit'); }
  private function begin(string $phase): void { $this->phase = $phase; $this->started = $this->time(); }
}
