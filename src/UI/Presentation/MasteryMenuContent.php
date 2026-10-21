<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
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
   * @param list<MasteryMenuNode> $nodes Every node, in default study order.
   * @param array<string, string> $summary
   * @param array<string, string> $fields The focused node's details.
   * @param string $points The character's unspent points as the corner block shows them ("SP 120"); empty when none.
   * @param list<string> $detailStatus The focused node's status under its name (Learned, Learning 10/50, what unlocks it).
   */
  public function __construct(
    public Character $character,
    public string $title,
    public array $regions,
    public int $regionIndex,
    public array $nodes,
    public ?string $focusedNode,
    public array $summary,
    public string $detailTitle,
    public array $fields,
    public string $detailText,
    public string $emptyText,
    public string $description,
    public ?string $status,
    public ?MenuInfoText $infoModel = null,
    public string $points = '',
    public array $detailStatus = [],
  ) {}
}
