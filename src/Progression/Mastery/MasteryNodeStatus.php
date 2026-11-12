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
   * @param MasteryUnavailability|null $unavailability Why nobody can learn it yet, when that is so.
   */
  public function __construct(
    public readonly MasteryNode $node,
    public readonly MasteryNodeState $state,
    public readonly bool $affordable = false,
    public readonly ?int $opensAtLevel = null,
    public readonly array $missing = [],
    public readonly ?MasteryUnavailability $unavailability = null,
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

  /** Whether it is part of what the character can learn now or later: learned, open or locked, but not unavailable to everyone. */
  public bool $available {
    get => $this->state !== MasteryNodeState::UNAVAILABLE;
  }

  /**
   * Whether the character is offered it: it is available and priced, by
   * their own map or by the tree. A node they know through their role or a
   * book, which nothing prices, is learned but not offered.
   */
  public bool $offered {
    get => $this->available && $this->node->cost !== null;
  }
}
