<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Performance;

use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;

/** Outcome data returned to the game through the owning scene excursion. */
final readonly class PerformanceResult implements SceneExcursionResultInterface
{
  public function __construct(public int $completedPhrases, public int $totalPhrases) {}
}
