<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\UI\Text\MenuInfoText;

/**
 * Fresh read-only projection of one character's Mastery Map for the menu's
 * renderers, never saved state. The Terminal list and the graphical map are
 * drawn from the same projection and the same focus.
 */
final readonly class MasteryMenuContent
{
  /**
   * @param list<string> $regions Each region's label (its name and Learned/Total count), in map order.
   * @param list<MasteryMenuNode> $nodes Every node, in the order the map lists them.
   * @param array<string, string> $summary Label and value pairs beside the character block.
   * @param MenuDetailSheet|null $details The focused node's details; null when nothing is focused.
   * @param string $pointsLabel The project's label for Mastery points ("SP").
   * @param int $points The points the character holds.
   * @param MasteryPointsCount|null $pointsCount A spend still counting down, for renderers that animate it.
   */
  public function __construct(
    public Character $character,
    public string $title,
    public array $regions,
    public int $regionIndex,
    public array $nodes,
    public ?string $focusedNode,
    public array $summary,
    public ?MenuDetailSheet $details,
    public string $emptyText,
    public string $description,
    public ?string $status,
    public ?MenuInfoText $infoModel = null,
    public string $pointsLabel = 'SP',
    public int $points = 0,
    public ?MasteryPointsCount $pointsCount = null,
  ) {}

  /** The points total as the character block shows it at a moment ("SP 450"), counting down after a spend. */
  public function getPointsText(float $time): string
  {
    return sprintf('%s %d', $this->pointsLabel, $this->pointsCount?->getValueAt($time) ?? $this->points);
  }
}
