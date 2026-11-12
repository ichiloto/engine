<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * One character's Mastery Map: the whole mastery tree, which every character
 * can reach, on that character's terms. The character's map file
 * (`assets/Data/Mastery/<actor>.php`) states their own terms for some nodes
 * (the price and level they learn them at, what they build on for them);
 * every other node keeps the tree's terms. A character without a file meets
 * the tree on its terms alone. Every menu and renderer reads it the same way.
 *
 * A role's curriculum (the route a role recommends) and a character's
 * aptitude are not modelled yet. A curriculum will recommend tree nodes
 * without limiting what a character can reach; aptitude would vary a
 * character's terms.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryMap
{
  /** @var array<string, MasteryNode> Every tree node on this character's terms, by id, in the tree's order. */
  public array $nodes;
  /** @var array<string, MasteryTerms> The terms the character's map states, by node id, in the map's order. */
  public array $terms;

  /**
   * @param string $actorId The character whose map it is.
   * @param list<MasteryTerms> $terms The terms the character's map states.
   * @param MasteryTree $tree The project's mastery tree.
   * @throws InvalidArgumentException When terms repeat, name a node the tree lacks, require an unknown node, or requirements loop.
   */
  public function __construct(public string $actorId, array $terms, public MasteryTree $tree)
  {
    if (trim($actorId) === '') {
      throw new InvalidArgumentException('A mastery map names no actor.');
    }
    $byId = [];
    foreach ($terms as $entry) {
      if (isset($byId[$entry->id])) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery map has node %s twice.', $actorId, $entry->id));
      }
      if ($tree->findNode($entry->id) === null) {
        throw new InvalidArgumentException(sprintf('%s\'s mastery map gives terms for node %s, which the mastery tree does not have.', $actorId, $entry->id));
      }
      foreach ($entry->requires as $required) {
        if ($tree->findNode($required) === null) {
          throw new InvalidArgumentException(sprintf('%s\'s mastery node %s requires unknown node %s.', $actorId, $entry->id, $required));
        }
      }
      $byId[$entry->id] = $entry;
    }
    $nodes = [];
    foreach ($tree->nodes as $id => $definition) {
      $entry = $byId[$id] ?? null;
      $nodes[$id] = $entry === null
        ? new MasteryNode($definition, $definition->cost, $definition->opensAtLevel ?? 1, $tree->getRequirements($definition), null, $definition->hidden)
        : new MasteryNode($definition, $entry->cost, $entry->opensAtLevel, $entry->requires, $entry->position, $entry->hidden, true);
    }
    MasteryTree::refuseLoops(sprintf('%s\'s mastery map', $actorId), array_map(static fn(MasteryNode $node): array => $node->requires, $nodes));
    $this->terms = $byId;
    $this->nodes = $nodes;
  }

  /**
   * @param array<string, mixed> $data The character's map file.
   * @param MasteryTree $tree The project's mastery tree.
   */
  public static function fromArray(array $data, MasteryTree $tree): self
  {
    $nodes = $data['nodes'] ?? [];
    if (! is_array($nodes) || ! array_is_list($nodes)) {
      throw new InvalidArgumentException('A mastery map\'s nodes must be a list.');
    }

    return new self(strval($data['actor'] ?? ''), array_map(
      static fn(mixed $node): MasteryTerms => MasteryTerms::fromArray(is_array($node) ? $node : []), $nodes), $tree);
  }

  public function findNode(string $id): ?MasteryNode
  {
    return $this->nodes[$id] ?? null;
  }

  /**
   * The nodes whose own terms do not build on their chain step: for each
   * node the character's map prices without requiring the step before it in
   * its branch, that step. Validation reports them, so an author sees where
   * a character starts partway along a branch or skips a grade.
   *
   * @return array<string, string> The chain step left out, by the node that leaves it out.
   */
  public function findUnchainedTerms(): array
  {
    $unchained = [];
    foreach ($this->terms as $id => $entry) {
      $step = $this->tree->findChainPredecessor($this->tree->nodes[$id]);
      if ($step !== null && ! in_array($step->id, $entry->requires, true)) {
        $unchained[$id] = $step->id;
      }
    }

    return $unchained;
  }
}
