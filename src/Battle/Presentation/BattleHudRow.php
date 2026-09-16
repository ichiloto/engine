<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Semantic window content, without terminal padding or selection styling. */
final readonly class BattleHudRow
{
  public function __construct(
    public int $index,
    public string $label,
    public bool $selected,
    public bool $affordable = true,
    public string $description = '',
    public int $mpCost = 0,
  ) {}
}
