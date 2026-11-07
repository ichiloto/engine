<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Binds the authored flight interval to a distance on the command's elapsed clock. */
final readonly class BattleFlightClock
{
  private float $start;
  private float $arrival;
  private float $elapsedArrival;
  private float $authoredDuration;
  private float $flightDuration;
  private ?float $returnStart;
  private float $recoveryDelay;

  public function __construct(int $release, int $arrival, BattlePresentationPath $path, float $arenaWidth, float $speed,
    ?int $returnFrame = null, ?int $poseEndFrame = null)
  {
    $fps = BattleCommandTimeline::FPS;
    if ($release < 0 || $arrival <= $release || !is_finite($arenaWidth) || $arenaWidth <= 0
      || !is_finite($speed) || $speed <= 0 || !is_finite($path->distance)) {
      throw new InvalidArgumentException('Battle flight clock requires a finite path and an ordered flight interval.');
    }
    $this->start = $release / $fps;
    $this->arrival = $arrival / $fps;
    $this->authoredDuration = $this->arrival - $this->start;
    $this->flightDuration = max(1 / $fps, $path->distance / $arenaWidth / $speed);
    $this->elapsedArrival = $this->start + $this->flightDuration;
    $this->returnStart = $returnFrame === null ? null : $returnFrame / $fps;
    $this->recoveryDelay = $this->returnStart === null || $poseEndFrame === null ? 0
      : max(0, $poseEndFrame / $fps - ($this->elapsedArrival + ($this->returnStart - $this->arrival)));
  }

  public function getAuthoredSeconds(float $elapsed): float
  {
    if ($elapsed <= $this->start) { return $elapsed; }
    $afterRelease = $elapsed - $this->start;
    if ($elapsed < $this->elapsedArrival) {
      return $this->start + $afterRelease * $this->authoredDuration / $this->flightDuration;
    }
    $authored = $this->arrival + ($elapsed - $this->elapsedArrival);
    if ($this->recoveryDelay > 0 && $authored >= $this->returnStart) {
      if ($authored < $this->returnStart + $this->recoveryDelay) {
        // The live caster pose continues on elapsed time. Hold the last command
        // frame before the return route until that pose has finished.
        return $this->returnStart - 1 / BattleCommandTimeline::FPS;
      }
      return $this->returnStart + ($authored - ($this->returnStart + $this->recoveryDelay));
    }
    return $authored;
  }

  public function getElapsedSeconds(float $authored): float
  {
    if ($authored <= $this->start) { return $authored; }
    $afterRelease = $authored - $this->start;
    if ($authored < $this->arrival) {
      return $this->start + $afterRelease * $this->flightDuration / $this->authoredDuration;
    }
    $elapsed = $this->elapsedArrival + ($authored - $this->arrival);
    return $this->returnStart !== null && $authored >= $this->returnStart ? $elapsed + $this->recoveryDelay : $elapsed;
  }
}
