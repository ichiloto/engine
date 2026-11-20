<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;
use Ichiloto\Engine\Rendering\Sprites\SpriteFactsPreparation;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;
use Throwable;

/** Owns one PHP child and incremental cache reads for a source plan until use or cancellation. */
final class BattleArtworkPreparation
{
  private const int HYDRATION_BUDGET_NS = 5_000_000;

  private ?SpriteFactsPreparation $worker = null;
  private ?BattleArtworkFacts $hydrating = null;
  private int $nextCrop = 0;
  private int $attempts = 0;
  private int $reconciliations = 0;
  private bool $unavailable = false;

  /** @param Closure(): ?BattleArtworkFacts $collect */
  public function __construct(private readonly Closure $collect) {}

  public function __destruct() { $this->cancel(); }

  /** Reconcile against the current source snapshot before its geometry is consumed. */
  public function poll(): bool
  {
    if ($this->unavailable) { return true; }
    try {
      if ($this->worker !== null) {
        if (!$this->worker->poll()) { return false; }
        $this->worker = null;
      }
      if ($this->hydrating === null || $this->nextCrop === $this->hydrating->countCrops()) {
        $facts = ($this->collect)();
        if ($facts === null || $facts->hasCurrentFacts(memoryOnly: true)) {
          $this->resetHydration();
          $this->attempts = $this->reconciliations = 0;
          return true;
        }
        if ($this->hydrating !== null && ++$this->reconciliations > 2) {
          throw new RuntimeException('Battle source facts could not remain current during staged hydration.');
        }
        $this->hydrating = $facts;
        $this->nextCrop = 0;
      }
      $end = $this->hydrating->hydrate($this->nextCrop, hrtime(true) + self::HYDRATION_BUDGET_NS);
      if ($end !== null) {
        $this->nextCrop = $end;
        // Validate the current cohort in the consuming update, without loading disk records in that update.
        return false;
      }
      if ($this->attempts === 2) { throw new RuntimeException('Battle source facts changed during preparation.'); }
      $this->attempts++;
      $this->worker = $this->hydrating->startPreparation();
      $this->resetHydration();
      return false;
    } catch (Throwable $error) {
      $this->cancel();
      $this->unavailable = true;
      // The existing synchronous presentation path owns diagnostics and recovery when preparation is unavailable.
      Debug::warn('Asynchronous battle artwork preparation unavailable: ' . $error->getMessage());
      return true;
    }
  }

  /** Advance background work without repeating a finished readiness check. */
  public function pollWhilePending(): void
  {
    if ($this->worker !== null || $this->hydrating !== null) { $this->poll(); }
  }

  public function cancel(): void
  {
    $this->worker?->cancel();
    $this->worker = null;
    $this->resetHydration();
  }

  private function resetHydration(): void
  {
    $this->hydrating = null;
    $this->nextCrop = 0;
  }
}
