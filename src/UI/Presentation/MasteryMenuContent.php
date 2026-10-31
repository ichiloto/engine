<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\ActionHint;

/**
 * Fresh read-only projection of one character's Mastery board for the
 * menu's renderers, never saved state. The Terminal and the graphical board
 * are drawn from the same projection, the same focus and the same words.
 */
final readonly class MasteryMenuContent
{
  /**
   * @param string $role The character's role, which sets the curriculum.
   * @param list<MasteryMenuTab> $regions Every discipline, in board order.
   * @param list<MasteryMenuTab> $families The shown discipline's families, in board order.
   * @param MasteryMenuLevel $level The level the focus is on: a discipline, a family or a node of the tree.
   * @param list<MasteryMenuNode> $nodes The shown discipline's nodes and headings, in list order.
   * @param int $lanes How many lanes the shown discipline takes.
   * @param int $stages How many stages its deepest family takes.
   * @param string|null $focus The focused node or heading on the tree; null above the tree.
   * @param list<array{text: string, color: string}> $footer What the footer says about the focus, in order, with colour roles.
   * @param list<ActionHint> $hints The bindings the footer shows.
   * @param bool $curriculum Whether the role recommends a curriculum, which the board marks quietly.
   * @param list<string>|null $info The open Info sheet's lines, or null while it is closed; never an empty list.
   * @param MasteryLearnHold|null $hold Learn held down on the focus.
   * @param MasteryPointsCount|null $pointsCount A spend still counting down, for renderers that animate it.
   * @param bool $reducedMotion Whether the player asked for reduced motion: no pan, fill, drain or count.
   * @param array{node: string, at: float}|null $flare The node just learned and when, for its short accent.
   */
  public function __construct(
    public Character $character,
    public string $title,
    public string $role,
    public string $levelLabel,
    public string $pointsLabel,
    public int $points,
    public array $regions,
    public int $regionIndex,
    public array $families,
    public int $familyIndex,
    public MasteryMenuLevel $level,
    public array $nodes,
    public int $lanes,
    public int $stages,
    public ?string $focus,
    public MasteryCamera $camera,
    public MasteryMenuSelection $selection,
    public array $footer,
    public array $hints,
    public bool $curriculum = false,
    public ?array $info = null,
    public ?MasteryLearnHold $hold = null,
    public ?MasteryPointsCount $pointsCount = null,
    public bool $reducedMotion = false,
    public string $emptyText = '',
    public ?array $flare = null,
  ) {}

  /** The points total a drawn corner block shows at a moment: draining while Learn is held, counting down after a spend. */
  public function getPointsAt(float $time): int
  {
    if ($this->hold !== null && ! $this->reducedMotion) {
      return $this->hold->getPointsAt($time);
    }

    return $this->pointsCount?->getValueAt($time) ?? $this->points;
  }

  /** The focused node or heading. */
  public function findFocusedNode(): ?MasteryMenuNode
  {
    return array_find($this->nodes, fn(MasteryMenuNode $node): bool => $node->id === $this->focus);
  }

  /** The shown discipline. */
  public function findRegion(): ?MasteryMenuTab
  {
    return $this->regions[$this->regionIndex] ?? null;
  }
}
