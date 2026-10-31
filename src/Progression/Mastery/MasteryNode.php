<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * One node of the mastery tree as one character meets it: the tree's node
 * (its skill, region, branch and kind) on that character's terms, which are
 * their map's when it states any and the tree's otherwise. It names the
 * skill by its catalogue identity and owns no copy of it. Its position
 * places it on a graphical map and means nothing to its progression.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryNode
{
  /**
   * @param MasteryNodeDefinition $definition The tree's node.
   * @param int|null $cost The Mastery points (SP) it takes to learn, or null when nothing prices it for this character.
   * @param int $opensAtLevel The character level it opens at.
   * @param list<string> $requires The nodes that must be learned first.
   * @param array{x: float, y: float}|null $position Where a graphical map draws it, as fractions (0 to 1).
   * @param bool $hidden Whether menus keep it a secret ("???") while it is locked.
   * @param bool $mapped Whether the character's own map states these terms.
   */
  public function __construct(
    public readonly MasteryNodeDefinition $definition,
    public readonly ?int $cost,
    public readonly int $opensAtLevel = 1,
    public readonly array $requires = [],
    public readonly ?array $position = null,
    public readonly bool $hidden = false,
    public readonly bool $mapped = false,
  )
  {
  }

  public string $id {
    get => $this->definition->id;
  }

  /** The skill it teaches, by catalogue name; null for a foundation that grants none yet. */
  public ?string $skill {
    get => $this->definition->skill;
  }

  /** The name menus show. */
  public string $name {
    get => $this->definition->label;
  }

  public string $region {
    get => $this->definition->region;
  }

  public ?string $branch {
    get => $this->definition->branch;
  }

  public MasteryNodeKind $kind {
    get => $this->definition->kind;
  }
}
