<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * A node's state for one character, with what a locked node still needs and
 * whether the character holds the points to learn it.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryNodeStatus
{
  /**
   * @param bool $affordable Whether the character holds at least the node's price.
   * @param int|null $opensAtLevel The level it still waits for, when that locks it.
   * @param list<string> $missing The required nodes not yet learned.
   */
  public function __construct(
    public readonly MasteryNode $node,
    public readonly MasteryNodeState $state,
    public readonly bool $affordable = false,
    public readonly ?int $opensAtLevel = null,
    public readonly array $missing = [],
  )
  {
  }

  /** Whether the character can learn it now: open, and its price is held. */
  public bool $learnable {
    get => $this->state === MasteryNodeState::OPEN && $this->affordable;
  }

  /** Whether menus keep it a secret: a hidden node that is still locked. */
  public bool $secret {
    get => $this->node->hidden && $this->state === MasteryNodeState::LOCKED;
  }
}
