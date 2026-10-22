<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * A branch of a mastery region: one element, one aspect, or the region's
 * non-elemental practice, and its nodes in the order they progress. The
 * kinds of its nodes make its chain (see MasteryTree::findChainPredecessor);
 * the list's order is the order menus and editors show them in.
 *
 * A branch may grow within another branch of its region, as a sub-family of
 * that branch's family (a game might hold Time within Non-elemental). Its
 * nodes keep their own ids, so moving a branch in or out of another never
 * changes what a save records.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryBranch
{
  /** The keys a branch's data may hold. */
  public const array KEYS = ['id', 'region', 'name', 'kind', 'element', 'description', 'within', 'nodes'];

  /**
   * @param list<MasteryNodeDefinition> $nodes Its nodes, in the order they progress.
   * @param string|null $element The project element an element branch practises.
   * @param string|null $within The branch of the same region it grows within, by id; null when it grows from the region itself.
   * @throws InvalidArgumentException When its ladder or chain cannot be read.
   */
  public function __construct(
    public string $id,
    public string $region,
    public string $name,
    public MasteryBranchKind $kind,
    public array $nodes = [],
    public ?string $element = null,
    public string $description = '',
    public ?string $within = null,
  )
  {
    if (trim($id) === '' || $id !== trim($id)) {
      throw new InvalidArgumentException('A mastery branch needs an id without surrounding spaces.');
    }
    if ($within !== null && (trim($within) === '' || $within !== trim($within) || $within === $id)) {
      throw new InvalidArgumentException(sprintf('Mastery branch %s must grow within another branch, named by its id.', $id));
    }
    if (($kind === MasteryBranchKind::ELEMENT) !== ($element !== null && trim($element) !== '')) {
      throw new InvalidArgumentException(sprintf('Mastery branch %s: an element branch names its element, and no other branch does.', $id));
    }
    $grades = [];
    $byId = [];
    foreach ($nodes as $index => $node) {
      if ($node->kind === MasteryNodeKind::FOUNDATION && $index !== 0) {
        throw new InvalidArgumentException(sprintf('Mastery branch %s: its foundation %s must come first.', $id, $node->id));
      }
      if ($node->grade !== null) {
        if (isset($grades[$node->grade])) {
          throw new InvalidArgumentException(sprintf('Mastery branch %s has two nodes of grade %d.', $id, $node->grade));
        }
        $grades[$node->grade] = $node->id;
      }
      $byId[$node->id] = $node;
    }
    if ($grades !== [] && ! self::isContiguous(array_keys($grades))) {
      throw new InvalidArgumentException(sprintf('Mastery branch %s: its grades must climb from 1 without a gap.', $id));
    }
    $extended = [];
    foreach ($nodes as $node) {
      if ($node->extends === null) {
        continue;
      }
      $base = $byId[$node->extends] ?? throw new InvalidArgumentException(sprintf(
        'Mastery node %s extends %s, which is not on branch %s.', $node->id, $node->extends, $id));
      if ($base->kind !== MasteryNodeKind::SPECIAL || $base->phase !== MasteryPhase::DEMI) {
        throw new InvalidArgumentException(sprintf('Mastery node %s cannot extend %s: a full phase extends a demi phase.', $node->id, $base->id));
      }
      if (isset($extended[$base->id])) {
        throw new InvalidArgumentException(sprintf('Mastery node %s is extended by both %s and %s.', $base->id, $extended[$base->id], $node->id));
      }
      $extended[$base->id] = $node->id;
    }
  }

  /**
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the entry is malformed.
   */
  public static function fromArray(array $data): self
  {
    $id = strval($data['id'] ?? '');
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Mastery branch %s has unknown key "%s".', $id, $key));
      }
    }
    if (array_key_exists('within', $data) && ! is_string($data['within'])) {
      throw new InvalidArgumentException(sprintf('Mastery branch %s within must name a branch by its id.', $id));
    }
    $kind = MasteryBranchKind::tryFrom(strval($data['kind'] ?? '')) ?? throw new InvalidArgumentException(sprintf(
      'Mastery branch %s kind must be one of %s.', $id, implode(', ', array_column(MasteryBranchKind::cases(), 'value'))));
    $region = strval($data['region'] ?? '');
    $nodes = $data['nodes'] ?? [];
    if (! is_array($nodes) || ! array_is_list($nodes)) {
      throw new InvalidArgumentException(sprintf('Mastery branch %s nodes must be a list.', $id));
    }

    return new self(
      $id,
      $region,
      strval($data['name'] ?? $id),
      $kind,
      array_map(static fn(mixed $node): MasteryNodeDefinition => MasteryNodeDefinition::fromArray(is_array($node) ? $node : [], $region, $id), $nodes),
      isset($data['element']) ? strval($data['element']) : null,
      strval($data['description'] ?? ''),
      $data['within'] ?? null,
    );
  }

  /** The node at the top of its ladder of graded applications, if it has one. */
  public function findHighestGrade(): ?MasteryNodeDefinition
  {
    $top = null;
    foreach ($this->nodes as $node) {
      if ($node->grade !== null && ($top === null || $node->grade > $top->grade)) {
        $top = $node;
      }
    }

    return $top;
  }

  public function findFoundation(): ?MasteryNodeDefinition
  {
    return ($this->nodes[0] ?? null)?->kind === MasteryNodeKind::FOUNDATION ? $this->nodes[0] : null;
  }

  public function findGrade(int $grade): ?MasteryNodeDefinition
  {
    foreach ($this->nodes as $node) {
      if ($node->grade === $grade) {
        return $node;
      }
    }

    return null;
  }

  /** @param list<int> $grades */
  private static function isContiguous(array $grades): bool
  {
    sort($grades);

    return $grades === range(1, count($grades));
  }
}
