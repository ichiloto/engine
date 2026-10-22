<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * The mastery tree laid out as a board, the same for every character and
 * every renderer: each region (discipline) holds its families side by side,
 * and each family grows down from its top as a tree.
 *
 * A family's top is its foundation, or a heading when its branch has none
 * (and for a region's own trunk nodes). Every other node hangs from the
 * step before it in its branch's chain (MasteryTree::findChainPredecessor),
 * or from the family's top when it has no step there. A sub-family (a branch
 * growing within another) stands inside its outer family's tree: its top
 * hangs from the step its chain grows from, or from the outer family's top,
 * and its own nodes hang below it the same way. Places are regular:
 * a node's stage is how far down its family it hangs, and its lane is the
 * middle of the lanes its own descendants take, so siblings never overlap
 * and every edge runs straight down and across. A graphical renderer turns
 * lanes and stages into pixels with its theme's spacing; a list follows the
 * same order (MasteryBoard::getPlaceIds). Nothing here depends on what any
 * character has learned.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryBoard
{
  /** @var array<string, list<MasteryFamily>> Each region's families, in the order the board shows them, by region id; sub-families sit within them. */
  public readonly array $families;
  /**
   * @var array<string, array{lane: float, stage: int, parent: ?string, family: string, subfamily: string, region: string}> Where
   *   each node and heading stands, by its id (a heading's id is MasteryFamily::getHeadingId()): its lane's middle (0.5 is the
   *   first lane's middle), its stage, what it hangs from, the family its discipline lists it in, the family or sub-family
   *   whose own place it is (the same family, unless it stands in a sub-family), and its region.
   */
  public readonly array $places;
  /** @var array<string, list<string>> What hangs from each node or heading, in board order, by its id. */
  public readonly array $children;
  /** @var array<string, int> How many lanes each region's families take together, by region id. */
  public readonly array $widths;
  /** @var array<string, int> How many stages each region's deepest family takes, by region id. */
  public readonly array $depths;

  public function __construct(public readonly MasteryTree $tree)
  {
    $families = [];
    foreach ($tree->regions as $region) {
      $branches = array_values(array_filter($tree->branches, static fn(MasteryBranch $branch): bool => $branch->region === $region->id));
      $families[$region->id] = array_map(static fn(MasteryBranch $branch): MasteryFamily => self::createFamily($branch, $branches),
        array_values(array_filter($branches, static fn(MasteryBranch $branch): bool => $branch->within === null)));
      if ($region->nodes !== []) {
        $families[$region->id][] = new MasteryFamily(MasteryFamily::getTrunkId($region->id), $region->id, $region->name, null, null,
          array_map(static fn(MasteryNodeDefinition $node): string => $node->id, $region->nodes));
      }
    }
    $places = $children = $widths = $depths = [];
    foreach ($families as $regionId => $regionFamilies) {
      $left = 0.0;
      $depth = 0;
      foreach ($regionFamilies as $family) {
        $root = self::getRootId($family);
        $hanging = [$root => []];
        $subfamilies = [];
        self::hang($tree, $family, $hanging, $subfamilies);
        $span = self::measure($root, $hanging);
        self::place($root, null, $left, 0, $hanging, $family, $subfamilies, $places, $depth);
        foreach ($hanging as $id => $below) {
          $children[$id] = $below;
        }
        $left += $span;
      }
      $widths[$regionId] = (int)$left;
      $depths[$regionId] = $depth;
    }
    $this->families = $families;
    $this->places = $places;
    $this->children = $children;
    $this->widths = $widths;
    $this->depths = $depths;
  }

  /** @return list<MasteryFamily> A region's families, in board order. */
  public function getFamilies(string $region): array
  {
    return $this->families[$region] ?? [];
  }

  /** A family or sub-family, by id. */
  public function findFamily(string $familyId): ?MasteryFamily
  {
    $search = array_merge([], ...array_values($this->families));
    while (($family = array_shift($search)) !== null) {
      if ($family->id === $familyId) {
        return $family;
      }
      array_push($search, ...$family->subfamilies);
    }

    return null;
  }

  /** The family a node or heading belongs to, as its discipline lists it. */
  public function findPlaceFamily(string $placeId): ?MasteryFamily
  {
    $place = $this->places[$placeId] ?? null;

    return $place === null ? null : $this->findFamily($place['family']);
  }

  /** The family or sub-family whose own place a node or heading is: the one that names, summarises and pictures it. */
  public function findPlaceSubfamily(string $placeId): ?MasteryFamily
  {
    $place = $this->places[$placeId] ?? null;

    return $place === null ? null : $this->findFamily($place['subfamily']);
  }

  /** The id of what stands at a family's top: its foundation, or its heading. */
  public static function getRootId(MasteryFamily $family): string
  {
    return $family->foundation ?? MasteryFamily::getHeadingId($family->id);
  }

  /** Whether an id names a family's heading rather than a node. */
  public function isHeading(string $placeId): bool
  {
    return isset($this->places[$placeId]) && ! isset($this->tree->nodes[$placeId]);
  }

  /**
   * Every node and heading of a family, top first, each followed by what
   * hangs from it: the order a list shows them in.
   *
   * @return list<string>
   */
  public function getFamilyPlaceIds(MasteryFamily $family): array
  {
    $ids = [];
    $visit = function (string $id) use (&$visit, &$ids): void {
      $ids[] = $id;
      foreach ($this->children[$id] ?? [] as $child) {
        $visit($child);
      }
    };
    $visit(self::getRootId($family));

    return $ids;
  }

  /** @return list<string> Every node and heading of a region, family by family, in list order. */
  public function getPlaceIds(string $region): array
  {
    return array_merge([], ...array_map($this->getFamilyPlaceIds(...), $this->getFamilies($region)));
  }

  /**
   * A branch's family, with the sub-families of the branches growing within it.
   *
   * @param list<MasteryBranch> $branches The branches of its region, in the order the tree declares them.
   */
  private static function createFamily(MasteryBranch $branch, array $branches): MasteryFamily
  {
    $subfamilies = array_map(static fn(MasteryBranch $inner): MasteryFamily => self::createFamily($inner, $branches),
      array_values(array_filter($branches, static fn(MasteryBranch $inner): bool => $inner->within === $branch->id)));

    return new MasteryFamily($branch->id, $branch->region, $branch->name, $branch, $branch->findFoundation()?->id,
      [...array_map(static fn(MasteryNodeDefinition $node): string => $node->id, $branch->nodes),
        ...array_merge([], ...array_map(static fn(MasteryFamily $family): array => $family->nodes, $subfamilies))],
      $branch->within, $subfamilies);
  }

  /**
   * Hangs a family's own nodes from the step before each in its chain, or from its top, and each
   * sub-family's top from the step its chain grows from, or from this family's top.
   *
   * @param array<string, list<string>> $hanging What hangs from each place, by id.
   * @param array<string, string> $subfamilies The family or sub-family whose own place each is, by place id.
   */
  private static function hang(MasteryTree $tree, MasteryFamily $family, array &$hanging, array &$subfamilies): void
  {
    $root = self::getRootId($family);
    $hanging[$root] ??= [];
    $subfamilies[$root] = $family->id;
    $hangFrom = static function (?string $step) use ($family, $root): string {
      return $step !== null && in_array($step, $family->nodes, true) ? $step : $root;
    };
    foreach ($family->ownNodes as $id) {
      if ($id === $root) {
        continue;
      }
      $hanging[$hangFrom($tree->findChainPredecessor($tree->nodes[$id])?->id)][] = $id;
      $hanging[$id] ??= [];
      $subfamilies[$id] = $family->id;
    }
    foreach ($family->subfamilies as $subfamily) {
      $top = $subfamily->foundation === null ? null : $tree->findChainPredecessor($tree->nodes[$subfamily->foundation])?->id;
      $hanging[$hangFrom($top)][] = self::getRootId($subfamily);
      self::hang($tree, $subfamily, $hanging, $subfamilies);
    }
  }

  /**
   * @param array<string, list<string>> $hanging
   * @return float How many lanes a node's subtree takes.
   */
  private static function measure(string $id, array $hanging): float
  {
    $children = $hanging[$id] ?? [];

    return $children === [] ? 1.0 : array_sum(array_map(static fn(string $child): float => self::measure($child, $hanging), $children));
  }

  /**
   * @param array<string, list<string>> $hanging
   * @param array<string, string> $subfamilies The family or sub-family whose own place each is, by place id.
   * @param array<string, array{lane: float, stage: int, parent: ?string, family: string, subfamily: string, region: string}> $places
   * @return float The lane middle the node takes.
   */
  private static function place(string $id, ?string $parent, float $left, int $stage, array $hanging, MasteryFamily $family,
    array $subfamilies, array &$places, int &$depth): float
  {
    $children = $hanging[$id] ?? [];
    $depth = max($depth, $stage + 1);
    if ($children === []) {
      $lane = $left + 0.5;
    } else {
      $middles = [];
      foreach ($children as $child) {
        $middles[] = self::place($child, $id, $left, $stage + 1, $hanging, $family, $subfamilies, $places, $depth);
        $left += self::measure($child, $hanging);
      }
      $lane = ($middles[0] + $middles[count($middles) - 1]) / 2;
    }
    $places[$id] = ['lane' => $lane, 'stage' => $stage, 'parent' => $parent, 'family' => $family->id,
      'subfamily' => $subfamilies[$id] ?? $family->id, 'region' => $family->region];

    return $lane;
  }
}
