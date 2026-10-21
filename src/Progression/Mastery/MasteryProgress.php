<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;
use JsonSerializable;

/**
 * What one character has done on their Mastery Map, and only that: the
 * Mastery points (SP) they hold and the nodes they have learned. The map
 * itself, its prices and the skills it teaches stay in the project's data.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryProgress implements JsonSerializable
{
  /**
   * Keys of the progress saved before Mastery spent points: the node then
   * studied, EXP put into unfinished nodes, and EXP banked while nothing was
   * open. A project converts them with a content migration; they are never
   * read as points.
   */
  public const array EXPERIENCE_KEYS = ['studying', 'progress', 'banked'];

  /**
   * @param int $points The Mastery points (SP) the character holds, unspent.
   * @param list<string> $learned The nodes learned.
   */
  public function __construct(
    public int $points = 0,
    public array $learned = [],
  )
  {
    if ($points < 0) {
      throw new InvalidArgumentException('A character cannot hold fewer than 0 Mastery points.');
    }
  }

  /**
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data still holds EXP progress no content migration converted.
   */
  public static function fromArray(array $data): self
  {
    $unconverted = array_values(array_intersect(self::EXPERIENCE_KEYS, array_keys($data)));
    if ($unconverted !== []) {
      throw new InvalidArgumentException(sprintf('Mastery progress still holds EXP (%s) from before Mastery spent points; '
        . 'the project needs a content migration that converts it.', implode(', ', $unconverted)));
    }
    $learned = array_values(array_unique(array_filter(is_array($data['learned'] ?? null) ? $data['learned'] : [], 'is_string')));

    return new self(max(0, intval($data['points'] ?? 0)), $learned);
  }

  public function hasLearned(string $node): bool
  {
    return in_array($node, $this->learned, true);
  }

  /** Records a node as learned. */
  public function markLearned(string $node): void
  {
    if (! $this->hasLearned($node)) {
      $this->learned[] = $node;
    }
  }

  /** Adds Mastery points the character earned. */
  public function addPoints(int $points): void
  {
    if ($points < 0) {
      throw new InvalidArgumentException('Mastery point awards cannot be negative.');
    }
    $this->points += $points;
  }

  /** @return array<string, mixed> */
  public function jsonSerialize(): array
  {
    return array_filter([
      'points' => $this->points,
      'learned' => $this->learned,
    ], static fn(mixed $value): bool => $value !== [] && $value !== 0);
  }
}
