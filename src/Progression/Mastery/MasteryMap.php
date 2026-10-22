<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * One character's Mastery Map: the nodes that character can learn, owned by
 * the project's data and read the same way by every menu and renderer.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryMap
{
  /** @var array<string, MasteryNode> The nodes, by id, in the order the map lists them. */
  public array $nodes;

  /**
   * @param string $actorId The character whose map it is.
   * @param list<MasteryNode> $nodes Its nodes.
   * @param list<string> $regionIds The regions the project declares.
   * @throws InvalidArgumentException When ids repeat, a region or requirement is unknown, or requirements loop.
   */
  public function __construct(public string $actorId, array $nodes, array $regionIds)
  {
    if (trim($actorId) === '') {
      throw new InvalidArgumentException('A mastery map names no actor.');
    }
    $byId = [];
    foreach ($nodes as $node) {
      if (isset($byId[$node->id])) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery map has node %s twice.', $actorId, $node->id));
      }
      if (! in_array($node->region, $regionIds, true)) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery node %s sits in unknown region "%s".', $actorId, $node->id, $node->region));
      }
      $byId[$node->id] = $node;
    }
    foreach ($byId as $node) {
      foreach ($node->requires as $required) {
        if (! isset($byId[$required])) {
          throw new InvalidArgumentException(sprintf('%s\'s mastery node %s requires unknown node %s.', $actorId, $node->id, $required));
        }
      }
      if (array_count_values(array_column($nodes, 'skill'))[$node->skill] > 1) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery map teaches %s on more than one node.', $actorId, $node->skill));
      }
    }
    self::refuseLoops($actorId, $byId);
    $this->nodes = $byId;
  }

  /**
   * @param array<string, mixed> $data The map's file.
   * @param list<string> $regionIds The regions the project declares.
   */
  public static function fromArray(array $data, array $regionIds): self
  {
    $nodes = $data['nodes'] ?? [];
    if (! is_array($nodes) || ! array_is_list($nodes)) {
      throw new InvalidArgumentException('A mastery map\'s nodes must be a list.');
    }

    return new self(strval($data['actor'] ?? ''), array_map(
      static fn(mixed $node): MasteryNode => MasteryNode::fromArray(is_array($node) ? $node : []), $nodes), $regionIds);
  }

  public function findNode(string $id): ?MasteryNode
  {
    return $this->nodes[$id] ?? null;
  }

  /** @param array<string, MasteryNode> $nodes */
  private static function refuseLoops(string $actorId, array $nodes): void
  {
    $settled = [];
    $visit = static function (string $id, array $path) use (&$visit, &$settled, $nodes, $actorId): void {
      if (isset($settled[$id])) {
        return;
      }
      if (in_array($id, $path, true)) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery nodes require each other in a loop: %s.', $actorId,
          implode(' -> ', [...array_slice($path, array_search($id, $path, true)), $id])));
      }
      foreach ($nodes[$id]->requires as $required) {
        $visit($required, [...$path, $id]);
      }
      $settled[$id] = true;
    };
    foreach (array_keys($nodes) as $id) {
      $visit($id, []);
    }
  }
}
