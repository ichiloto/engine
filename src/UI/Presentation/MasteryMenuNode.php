<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;

/** One node as the Mastery menu shows it. */
final readonly class MasteryMenuNode
{
  /**
   * @param string $label Its skill, or "???" while it is a secret.
   * @param int $region The index of the region it sits in.
   * @param string $value Its status as the card shows it (a learned mark, its SP price, Lv 5, ???).
   * @param list<string> $requires The ids of the nodes it builds on.
   * @param array{x: float, y: float}|null $position Where the authored map places it, 0 to 1 across the map.
   * @param bool $affordable Whether the character holds its price.
   */
  public function __construct(
    public string $id,
    public string $label,
    public int $region,
    public MasteryNodeState $state,
    public string $value,
    public array $requires = [],
    public ?array $position = null,
    public bool $affordable = true,
  ) {}
}
