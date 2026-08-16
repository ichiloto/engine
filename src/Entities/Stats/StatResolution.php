<?php

namespace Ichiloto\Engine\Entities\Stats;

/** Inspectable result of every persistent stat layer and its cap. */
final readonly class StatResolution
{
  public int $uncappedValue;
  public int $effectiveValue;
  public int $capLoss;
  public int $remainingHeadroom;

  public function __construct(
    public StatKey $stat,
    public int $natural,
    public int $actorNatural,
    public int $permanent,
    public int $equipment,
    public int $temporary,
    public int $cap,
  )
  {
    $this->uncappedValue = $natural + $actorNatural + $permanent + $equipment + $temporary;
    $this->effectiveValue = min($cap, max(0, $this->uncappedValue));
    $this->capLoss = max(0, $this->uncappedValue - $this->effectiveValue);
    $this->remainingHeadroom = max(0, $cap - $this->effectiveValue);
  }
}
