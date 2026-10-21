<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;

/** One node as the Mastery menu shows it. */
final readonly class MasteryMenuNode
{
  /**
   * @param int $region The index of the region it sits in.
   * @param string $value Its state in words (Learned, Studying 40 / 162 EXP, Opens at Lv 5).
   * @param list<string> $requires The ids of the nodes it builds on.
   * @param array{x: float, y: float}|null $position Where the authored map places it, 0 to 1 across the map.
   */
  public function __construct(
    public string $id,
    public string $label,
    public int $region,
    public MasteryNodeState $state,
    public string $value,
    public array $requires = [],
    public ?array $position = null,
  ) {}
}
