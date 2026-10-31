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
 * a node's stage is how far down its family it hangs, siblings stand side
 * by side under what they hang from, centred on it, and never overlap, and
 * every edge runs straight down and across. A row of siblings wider than
 * ROW_LANES wraps onto further rows below, each as wide as the others can
 * make it: what they hang from stands on a line straight down through the
 * gap between two of each earlier row's subtrees, so its line runs down
 * past those rows to the next, and every sibling stays within reach of it.
 * A graphical renderer turns lanes and stages into pixels with its theme's
 * spacing; a list follows the same order (MasteryBoard::getPlaceIds), which
 * wrapping never changes. Nothing here depends on what any character has
 * learned.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryBoard
{
  /**
   * The most lanes a row of siblings spans before it wraps onto another row. Four keeps every sibling within
   * one and a half lanes of what it hangs from, so a view a few lanes wide shows both.
   */
  public const int ROW_LANES = 4;

  /** @var array<string, list<MasteryFamily>> Each region's families, in the order the board shows them, by region id; sub-families sit within them. */
  public readonly array $families;
  /**
   * @var array<string, array{lane: float, stage: int, row: int, parent: ?string, family: string, subfamily: string, region: string}>
   *   Where each node and heading stands, by its id (a heading's id is MasteryFamily::getHeadingId()): its lane's middle (0.5 is
   *   the first lane's middle), its stage, which row of its siblings it stands in (0 unless a wide row wrapped), what it hangs
   *   from, the family its discipline lists it in, the family or sub-family whose own place it is (the same family, unless it
   *   stands in a sub-family), and its region.
   */
  public readonly array $places;
  /** @var array<string, list<string>> What hangs from each node or heading, in board order, by its id. */
  public readonly array $children;
  /** @var array<string, int> How many lanes each region's families take together, by region id, rounded up. */
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
        $arranged = self::arrange($root, $hanging);
        foreach ($arranged['places'] as $id => [$x, $stage, $row]) {
          $places[$id] = ['lane' => $left + $x, 'stage' => $stage, 'row' => $row, 'parent' => $arranged['parents'][$id] ?? null,
            'family' => $family->id, 'subfamily' => $subfamilies[$id] ?? $family->id, 'region' => $family->region];
        }
        foreach ($hanging as $id => $below) {
          $children[$id] = $below;
        }
        $depth = max($depth, $arranged['depth']);
        $left += $arranged['width'];
      }
      $widths[$regionId] = (int)ceil($left - 1e-9);
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
   * Lays out a place's subtree: its siblings in rows of at most ROW_LANES lanes (or one subtree, when that is
   * wider), the place over the middle of its only or last row and on a line through a gap in every earlier one.
   *
   * @param array<string, list<string>> $hanging
   * @return array{width: float, depth: int, places: array<string, array{float, int, int}>, parents: array<string, string>} How
   *   many lanes and stages the subtree takes, and each place's lane, stage and row within it, from its left and its top.
   */
  private static function arrange(string $id, array $hanging): array
  {
    $children = $hanging[$id] ?? [];
    if ($children === []) {
      return ['width' => 1.0, 'depth' => 1, 'places' => [$id => [0.5, 0, 0]], 'parents' => []];
    }
    $subtrees = array_map(static fn(string $child): array => self::arrange($child, $hanging), $children);
    $rows = self::wrap(array_column($subtrees, 'width'));
    // Lanes are measured from the place's own line until the whole is known.
    $places = [$id => [0.0, 0, 0]];
    $parents = [];
    [$left, $right] = [-0.5, 0.5];
    $stage = 1;
    foreach ($rows as $row => $members) {
      $widths = array_map(static fn(int $index): float => $subtrees[$index]['width'], $members);
      $start = $row === array_key_last($rows) ? self::centreRow($members, $subtrees) : -self::findGap($widths);
      $x = $start;
      $depth = 0;
      foreach ($members as $index) {
        $subtree = $subtrees[$index];
        foreach ($subtree['places'] as $placeId => [$lane, $placeStage, $placeRow]) {
          $places[$placeId] = [$x + $lane, $stage + $placeStage, $placeId === $children[$index] ? $row : $placeRow];
        }
        $parents[$children[$index]] = $id;
        $parents += $subtree['parents'];
        $x += $subtree['width'];
        $depth = max($depth, $subtree['depth']);
      }
      [$left, $right] = [min($left, $start), max($right, $x)];
      $stage += $depth;
    }

    return ['width' => $right - $left, 'depth' => $stage,
      'places' => array_map(static fn(array $place): array => [$place[0] - $left, $place[1], $place[2]], $places), 'parents' => $parents];
  }

  /**
   * Splits siblings' widths into as few rows as fit ROW_LANES (a wider subtree takes a row alone), then evens the rows out.
   *
   * @param list<float> $widths
   * @return list<list<int>> Each row's siblings, by index, in order.
   */
  private static function wrap(array $widths): array
  {
    $pack = static function (float $capacity) use ($widths): array {
      $rows = [[]];
      $used = 0.0;
      foreach ($widths as $index => $width) {
        $row = array_key_last($rows);
        if ($rows[$row] !== [] && $used + $width > $capacity + 1e-9) {
          $rows[] = [];
          $row++;
          $used = 0.0;
        }
        $rows[$row][] = $index;
        $used += $width;
      }

      return $rows;
    };
    $widest = max(self::ROW_LANES, max($widths));
    $count = count($pack($widest));
    $capacity = max(max($widths), ceil(array_sum($widths) / $count - 1e-9));
    while (count($pack($capacity)) > $count) {
      $capacity++;
    }

    return $pack(min($capacity, $widest));
  }

  /**
   * Where a row that the place's line runs down through starts, left of that line: at the boundary between two of its subtrees
   * nearest its middle, so the line never crosses one.
   *
   * @param list<float> $widths
   */
  private static function findGap(array $widths): float
  {
    $total = array_sum($widths);
    $best = 0.0;
    $boundary = 0.0;
    foreach (array_slice($widths, 0, -1) as $width) {
      $boundary += $width;
      if ($best === 0.0 || abs($boundary - $total / 2) < abs($best - $total / 2) - 1e-9) {
        $best = $boundary;
      }
    }

    return $best;
  }

  /**
   * Where the last row starts, left of the place's line, so the place stands over the middle of its first and last sibling.
   *
   * @param list<int> $members
   * @param list<array{width: float, places: array<string, array{float, int, int}>}> $subtrees
   */
  private static function centreRow(array $members, array $subtrees): float
  {
    $first = $subtrees[$members[0]];
    $last = $subtrees[$members[array_key_last($members)]];
    $before = array_sum(array_map(static fn(int $index): float => $subtrees[$index]['width'], array_slice($members, 0, -1)));

    return -(reset($first['places'])[0] + $before + reset($last['places'])[0]) / 2;
  }
}
