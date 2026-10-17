<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

use Ichiloto\Engine\UI\Navigation\FocusDirection;
use InvalidArgumentException;

/** Explicit directed edges and one stable focus, with no presentation or input ownership. */
final class DirectionalFocusGraph
{
  /** @var array<string, DirectionalFocusNode> */
  private array $nodes = [];
  private ?string $focusedId = null;

  /** @param list<DirectionalFocusNodeInterface> $nodes */
  public function __construct(array $nodes = [], ?string $fallbackId = null)
  {
    $this->replaceNodes($nodes, $fallbackId);
  }

  /** Build cardinal neighbours for spatial UI markers. This never supplies gameplay links.
   * @param array<string,array{0:int|float,1:int|float}> $positions
   */
  public static function createFromPositions(array $positions, ?string $fallbackId = null): self
  {
    $scale=1.0;
    foreach ($positions as $id=>$point) {
      if (trim((string)$id)==='' || !is_array($point) || count($point)!==2
        || !isset($point[0],$point[1]) || (!is_int($point[0]) && !is_float($point[0]))
        || (!is_int($point[1]) && !is_float($point[1]))
        || !is_finite((float)$point[0]) || !is_finite((float)$point[1])) {
        throw new InvalidArgumentException('Spatial focus needs stable ids and finite coordinate pairs.');
      }
      $scale=max($scale,abs((float)$point[0]),abs((float)$point[1]));
    }
    $positions=array_map(static fn(array $point)=>[$point[0]/$scale,$point[1]/$scale],$positions);
    $nodes=[];
    foreach ($positions as $id=>[$x,$y]) {
      $neighbours=[];
      foreach (FocusDirection::cases() as $direction) {
        $best=null; $score=INF;
        foreach ($positions as $other=>[$otherX,$otherY]) {
          $dx=$otherX-$x; $dy=$otherY-$y;
          $forward=match($direction) { FocusDirection::UP=>-$dy, FocusDirection::RIGHT=>$dx,
            FocusDirection::DOWN=>$dy, FocusDirection::LEFT=>-$dx };
          $side=match($direction) { FocusDirection::UP,FocusDirection::DOWN=>abs($dx), default=>abs($dy) };
          if ($forward>0 && $forward+2*$side<$score) { $best=(string)$other; $score=$forward+2*$side; }
        }
        $neighbours[$direction->value]=$best;
      }
      $nodes[]=new DirectionalFocusNode((string)$id,up:$neighbours['up'],right:$neighbours['right'],
        down:$neighbours['down'],left:$neighbours['left']);
    }
    return new self($nodes,$fallbackId);
  }

  /**
   * Preserve focus by ID, otherwise require a named fallback for a nonempty graph.
   * An empty graph clears focus. Supplied definitions are copied before validation;
   * any rejection leaves the previous graph and focus untouched.
   *
   * @param list<DirectionalFocusNodeInterface> $nodes
   */
  public function replaceNodes(array $nodes, ?string $fallbackId = null): void
  {
    if (!array_is_list($nodes)) {
      throw new InvalidArgumentException('Directional focus nodes must be a list.');
    }

    $nextNodes = [];
    foreach ($nodes as $definition) {
      if (!$definition instanceof DirectionalFocusNodeInterface) {
        throw new InvalidArgumentException('Directional focus nodes must implement the shared node contract.');
      }
      $node = new DirectionalFocusNode(
        $definition->id,
        $definition->enabled,
        $definition->getNeighbourId(FocusDirection::UP),
        $definition->getNeighbourId(FocusDirection::RIGHT),
        $definition->getNeighbourId(FocusDirection::DOWN),
        $definition->getNeighbourId(FocusDirection::LEFT),
      );
      if (isset($nextNodes[$node->id])) {
        throw new InvalidArgumentException("Duplicate directional focus node ID: {$node->id}.");
      }
      $nextNodes[$node->id] = $node;
    }

    foreach ($nextNodes as $node) {
      foreach (FocusDirection::cases() as $direction) {
        $neighbourId = $node->getNeighbourId($direction);
        if ($neighbourId !== null && !isset($nextNodes[$neighbourId])) {
          throw new InvalidArgumentException("Unknown {$direction->name} neighbour {$neighbourId} for {$node->id}.");
        }
      }
    }
    if ($fallbackId !== null && !isset($nextNodes[$fallbackId])) {
      throw new InvalidArgumentException("Unknown directional focus fallback ID: {$fallbackId}.");
    }

    $nextFocusedId = $this->focusedId !== null && isset($nextNodes[$this->focusedId])
      ? $this->focusedId : $fallbackId;
    if ($nextNodes !== [] && $nextFocusedId === null) {
      throw new InvalidArgumentException('A nonempty directional focus graph requires a named fallback when focus cannot be preserved.');
    }

    $this->nodes = $nextNodes;
    $this->focusedId = $nextFocusedId;
  }

  /** Move along exactly one edge; absent neighbours and self edges do not change focus. */
  public function moveFocus(FocusDirection $direction): bool
  {
    $neighbourId = $this->getFocusedNode()?->getNeighbourId($direction);
    return $neighbourId !== null && $this->setFocus($neighbourId);
  }

  /** Select an existing ID, including a disabled node; report whether focus changed. */
  public function setFocus(string $id): bool
  {
    if (!isset($this->nodes[$id])) {
      throw new InvalidArgumentException("Unknown directional focus node ID: {$id}.");
    }
    if ($this->focusedId === $id) {
      return false;
    }
    $this->focusedId = $id;
    return true;
  }

  public function getFocusedNode(): ?DirectionalFocusNode
  {
    return $this->focusedId === null ? null : $this->nodes[$this->focusedId];
  }
}
