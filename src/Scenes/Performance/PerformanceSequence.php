<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Performance;

use Ichiloto\Engine\Scenes\SceneExcursionOutcome;

/** An untimed performance sitting. Progress belongs to this instance alone. */
final class PerformanceSequence
{
  private int $completedPhrases = 0;
  public private(set) ?SceneExcursionOutcome $outcome = null;

  public function __construct(private readonly PerformanceSceneConfiguration $configuration) {}

  public function getCurrentPhrase(): ?PerformancePhrase
  {
    return $this->outcome === null ? $this->configuration->phrases[$this->completedPhrases] : null;
  }

  /** A different choice leaves the current phrase available for another attempt. */
  public function confirmChoice(int $choice): bool
  {
    if ($this->getCurrentPhrase()?->matchingChoice !== $choice) { return false; }
    if (++$this->completedPhrases === count($this->configuration->phrases)) {
      $this->outcome = SceneExcursionOutcome::complete($this->getResult());
    }
    return true;
  }

  public function cancel(): void
  {
    $this->outcome ??= SceneExcursionOutcome::cancel($this->getResult());
  }

  private function getResult(): PerformanceResult
  {
    return new PerformanceResult($this->completedPhrases, count($this->configuration->phrases));
  }
}
