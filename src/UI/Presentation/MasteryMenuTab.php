<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * A discipline or a family as the Mastery menu's tabs and overview show it:
 * its name and how much of it the character has learned.
 */
final readonly class MasteryMenuTab
{
  /**
   * @param string $id The region or family id.
   * @param string $name Its name.
   * @param int $learned How many of its skills the character has learned.
   * @param int $total How many skills it holds.
   * @param list<string> $iconRoles Theme icon roles for its picture, most particular first.
   */
  public function __construct(
    public string $id,
    public string $name,
    public int $learned,
    public int $total,
    public array $iconRoles = [],
  ) {}

  /** Its count as a tab shows it ("2/40"). */
  public function describeCount(): string
  {
    return sprintf('%d/%d', $this->learned, $this->total);
  }
}
