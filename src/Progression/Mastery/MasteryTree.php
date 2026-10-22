<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * The project's mastery tree, shared by every character: its regions, each
 * region's branches, and every node, read from `assets/Data/mastery.php`.
 * Every character can reach every node; a character's own map only states
 * the terms they learn some nodes on (see MasteryMap).
 *
 * A node builds on the step before it in its branch's chain:
 *
 * - a foundation begins the branch and builds on nothing;
 * - a grade I application, an ungraded application and a demi phase build
 *   on the foundation;
 * - each further grade builds on the grade below it;
 * - an advanced application builds on the branch's highest grade (or its
 *   foundation, when the branch has no grades);
 * - a full phase builds on the demi phase it extends.
 *
 * A step the branch does not have is skipped (a branch without a foundation
 * starts at its first grade). Nodes on a region's trunk build on nothing.
 * A node's own `requires` add to its chain step.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryTree
{
  /** @var array<string, MasteryNodeDefinition> Every node, by id, region by region, branches before trunk. */
  public array $nodes;
  /** @var array<string, MasteryBranch> The branches, by id. */
  public array $branches;

  /**
   * @param list<MasteryRegion> $regions The regions, in the order maps show them.
   * @param list<MasteryBranch> $branches The branches, in the order their regions list them.
   * @throws InvalidArgumentException When ids repeat, a branch's region or a required node is unknown, a skill is taught twice, or requirements loop.
   */
  public function __construct(public array $regions = [], array $branches = [])
  {
    $regionIds = [];
    foreach ($regions as $region) {
      if (isset($regionIds[$region->id])) {
        throw new InvalidArgumentException(sprintf('Mastery region %s is declared twice.', $region->id));
      }
      $regionIds[$region->id] = true;
    }
    $byBranch = [];
    foreach ($branches as $branch) {
      if (! isset($regionIds[$branch->region])) {
        throw new InvalidArgumentException(sprintf('Mastery branch %s grows from unknown region "%s".', $branch->id, $branch->region));
      }
      if (isset($byBranch[$branch->id])) {
        throw new InvalidArgumentException(sprintf('Mastery branch %s is declared twice.', $branch->id));
      }
      $byBranch[$branch->id] = $branch;
    }
    $nodes = [];
    $skills = [];
    foreach ($regions as $region) {
      $members = [...array_merge(...array_map(static fn(MasteryBranch $branch): array => $branch->nodes,
        array_values(array_filter($branches, static fn(MasteryBranch $branch): bool => $branch->region === $region->id)))), ...$region->nodes];
      foreach ($members as $node) {
        if (isset($nodes[$node->id])) {
          throw new InvalidArgumentException(sprintf('The mastery tree has node %s twice.', $node->id));
        }
        if ($node->skill !== null) {
          if (isset($skills[$node->skill])) {
            throw new InvalidArgumentException(sprintf('The mastery tree teaches %s on both %s and %s.', $node->skill, $skills[$node->skill], $node->id));
          }
          $skills[$node->skill] = $node->id;
        }
        $nodes[$node->id] = $node;
      }
    }
    $this->nodes = $nodes;
    $this->branches = $byBranch;
    foreach ($nodes as $node) {
      foreach ($node->requires as $required) {
        if (! isset($nodes[$required])) {
          throw new InvalidArgumentException(sprintf('Mastery node %s requires unknown node %s.', $node->id, $required));
        }
      }
    }
    self::refuseLoops('The mastery tree', array_map(fn(MasteryNodeDefinition $node): array => $this->getRequirements($node), $nodes));
  }

  /**
   * @param array<string, mixed> $data The settings file (`mastery.php`).
   * @throws InvalidArgumentException When the tree cannot be read.
   */
  public static function fromArray(array $data): self
  {
    foreach (['regions', 'branches'] as $key) {
      if (array_key_exists($key, $data) && (! is_array($data[$key]) || ! array_is_list($data[$key]))) {
        throw new InvalidArgumentException(sprintf('The mastery tree\'s %s must be a list.', $key));
      }
    }

    return new self(
      array_map(static fn(mixed $region): MasteryRegion => MasteryRegion::fromArray(is_array($region) ? $region : []), $data['regions'] ?? []),
      array_map(static fn(mixed $branch): MasteryBranch => MasteryBranch::fromArray(is_array($branch) ? $branch : []), $data['branches'] ?? []),
    );
  }

  /** @return list<string> The region ids, in map order. */
  public function getRegionIds(): array
  {
    return array_map(static fn(MasteryRegion $region): string => $region->id, $this->regions);
  }

  public function findNode(string $id): ?MasteryNodeDefinition
  {
    return $this->nodes[$id] ?? null;
  }

  /** The node teaching a skill, if the tree places it. */
  public function findSkillNode(string $skill): ?MasteryNodeDefinition
  {
    foreach ($this->nodes as $node) {
      if ($node->skill === $skill) {
        return $node;
      }
    }

    return null;
  }

  /** The step before a node in its branch's chain, if it has one. */
  public function findChainPredecessor(MasteryNodeDefinition $node): ?MasteryNodeDefinition
  {
    $branch = $node->branch === null ? null : $this->branches[$node->branch] ?? null;
    if ($branch === null) {
      return null;
    }
    $foundation = $branch->findFoundation();

    return match (true) {
      $node->kind === MasteryNodeKind::FOUNDATION => null,
      $node->extends !== null => $this->nodes[$node->extends] ?? null,
      $node->grade !== null && $node->grade > 1 => $branch->findGrade($node->grade - 1),
      $node->kind === MasteryNodeKind::ADVANCED => $branch->findHighestGrade() ?? $foundation,
      default => $foundation,
    };
  }

  /**
   * What a node needs learned first on the tree's terms: its chain step and
   * its own requirements.
   *
   * @return list<string>
   */
  public function getRequirements(MasteryNodeDefinition $node): array
  {
    $step = $this->findChainPredecessor($node)?->id;

    return array_values(array_unique([...($step === null ? [] : [$step]), ...$node->requires]));
  }


  /**
   * @param string $subject Whose nodes they are, for the message.
   * @param array<string, list<string>> $requirements Each node's requirements, by id.
   * @throws InvalidArgumentException When nodes require each other in a loop.
   */
  public static function refuseLoops(string $subject, array $requirements): void
  {
    $settled = [];
    $visit = static function (string $id, array $path) use (&$visit, &$settled, $requirements, $subject): void {
      if (isset($settled[$id])) {
        return;
      }
      if (in_array($id, $path, true)) {
        throw new InvalidArgumentException(sprintf('%s\'s nodes require each other in a loop: %s.', $subject,
          implode(' -> ', [...array_slice($path, array_search($id, $path, true)), $id])));
      }
      foreach ($requirements[$id] ?? [] as $required) {
        $visit($required, [...$path, $id]);
      }
      $settled[$id] = true;
    };
    foreach (array_keys($requirements) as $id) {
      $visit($id, []);
    }
  }
}
