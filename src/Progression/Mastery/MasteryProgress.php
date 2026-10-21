<?php

namespace Ichiloto\Engine\Progression\Mastery;

use JsonSerializable;

/**
 * What one character has done on their Mastery Map, and only that: the node
 * they study, the EXP put into each node they have not finished, the nodes
 * they have learned, and EXP banked while no node was open. The map itself
 * and the skills it teaches stay in the project's data.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryProgress implements JsonSerializable
{
  /**
   * @param string|null $studying The node the player chose to study next; null follows the default order.
   * @param array<string, int> $progress EXP put into each unfinished node.
   * @param list<string> $learned The nodes learned.
   * @param int $banked EXP earned while no node was open, for the next one that opens.
   */
  public function __construct(
    public ?string $studying = null,
    public array $progress = [],
    public array $learned = [],
    public int $banked = 0,
  )
  {
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    $progress = [];
    foreach (is_array($data['progress'] ?? null) ? $data['progress'] : [] as $node => $amount) {
      if (is_string($node) && is_int($amount) && $amount > 0) {
        $progress[$node] = $amount;
      }
    }
    $learned = array_values(array_unique(array_filter(is_array($data['learned'] ?? null) ? $data['learned'] : [], 'is_string')));
    $studying = $data['studying'] ?? null;

    return new self(is_string($studying) && $studying !== '' ? $studying : null, $progress, $learned, max(0, intval($data['banked'] ?? 0)));
  }

  public function hasLearned(string $node): bool
  {
    return in_array($node, $this->learned, true);
  }

  public function getProgress(string $node): int
  {
    return $this->progress[$node] ?? 0;
  }

  /** Records a node as learned; its progress is no longer kept. */
  public function markLearned(string $node): void
  {
    if (! $this->hasLearned($node)) {
      $this->learned[] = $node;
    }
    unset($this->progress[$node]);
    if ($this->studying === $node) {
      $this->studying = null;
    }
  }

  /** @return array<string, mixed> */
  public function jsonSerialize(): array
  {
    return array_filter([
      'studying' => $this->studying,
      'progress' => $this->progress,
      'learned' => $this->learned,
      'banked' => $this->banked,
    ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== 0);
  }
}
