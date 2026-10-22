<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * One family of a discipline (a mastery region) as a board shows it: one of
 * the region's branches, or the region's own trunk nodes gathered under the
 * region's name. A family is headed by its foundation when its branch has
 * one; otherwise a heading stands at its top, which can be focused but
 * never learned.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryFamily
{
  /**
   * @param string $id The branch id, or `trunk:<region>` for a region's own nodes. Boards use it; saves never do.
   * @param string $region The region it belongs to.
   * @param string $name Its name: the branch's, or the region's for its trunk.
   * @param MasteryBranch|null $branch The branch it shows, or null for a region's trunk.
   * @param string|null $foundation The node that heads it, when its branch has a foundation.
   * @param list<string> $nodes Every node in it, in the order its branch or trunk lists them.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $region,
    public readonly string $name,
    public readonly ?MasteryBranch $branch,
    public readonly ?string $foundation,
    public readonly array $nodes,
  )
  {
  }

  /** Whether a heading, not a node, stands at its top. */
  public bool $hasHeading {
    get => $this->foundation === null;
  }

  /** The element its branch practises, if it is an element branch. */
  public ?string $element {
    get => $this->branch?->element;
  }

  /** The id a board gives a region's trunk family. */
  public static function getTrunkId(string $region): string
  {
    return 'trunk:' . $region;
  }

  /** The id of the heading at the top of a family, which a board can focus like a node. */
  public static function getHeadingId(string $familyId): string
  {
    return 'family:' . $familyId;
  }
}
