<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * A node's state for one character, with what a locked node still needs and
 * how far the character has come with it.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryNodeStatus
{
  /**
   * @param int|null $opensAtLevel The level it still waits for, when that locks it.
   * @param list<string> $missing The required nodes not yet learned.
   */
  public function __construct(
    public readonly MasteryNode $node,
    public readonly MasteryNodeState $state,
    public readonly int $progress = 0,
    public readonly ?int $opensAtLevel = null,
    public readonly array $missing = [],
  )
  {
  }

  /** The EXP the node still needs. */
  public int $remaining {
    get => $this->state === MasteryNodeState::LEARNED ? 0 : max(0, $this->node->cost - $this->progress);
  }
}
