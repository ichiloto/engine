<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;

/**
 * One place on the Mastery board as the menu shows it: a node, or the
 * heading at the top of a family without a foundation. Its lane and stage
 * come from the shared board (MasteryBoard); the Terminal lists the same
 * places in the same order.
 */
final readonly class MasteryMenuNode
{
  /**
   * @param string $id The node's id, or the family heading's id.
   * @param string $label Its name, or "???" while it is a secret.
   * @param MasteryNodeState|null $state Where it stands for the character; null for a heading, which is never learned.
   * @param float $lane The middle of its lane on the board (0.5 is the first lane's middle).
   * @param int $stage How far down its family it hangs (0 is the family's top).
   * @param string|null $parent What it hangs from; null at a family's top.
   * @param string $family The family it belongs to, as its discipline lists it.
   * @param string|null $badge Its grade (I, II, III), phase (Demi, Full) or reach ("All foes"), drawn on its rim.
   * @param bool $badgeIsTarget Whether the badge names its reach, so the footer does not say it again.
   * @param list<string> $iconRoles Theme icon roles for its picture, most particular first; the first one the theme binds is drawn.
   * @param bool $affordable Whether the character holds its price.
   * @param bool $curriculum Whether the character's role recommends it.
   * @param string|null $price Its price as one figure with its label ("45 SP"), when it can still be learned.
   * @param MasteryMenuTab|null $heads The family or sub-family it stands at the top of, with its count; null for a place within one.
   */
  public function __construct(
    public string $id,
    public string $label,
    public ?MasteryNodeState $state,
    public float $lane,
    public int $stage,
    public ?string $parent,
    public string $family,
    public ?string $badge = null,
    public bool $badgeIsTarget = false,
    public array $iconRoles = [],
    public bool $affordable = false,
    public bool $curriculum = false,
    public ?string $price = null,
    public ?MasteryMenuTab $heads = null,
  ) {}

  /** Whether it stands at the top of a family, or of a sub-family within one, so it carries that family's name. */
  public function isFamilyTop(): bool
  {
    return $this->parent === null || $this->heads !== null;
  }

  /** Whether it is a family's heading rather than a node. */
  public function isHeading(): bool
  {
    return $this->state === null;
  }

  /** Whether the line from what it hangs from is lit: it is learned. */
  public function isLit(): bool
  {
    return $this->state === MasteryNodeState::LEARNED;
  }
}
