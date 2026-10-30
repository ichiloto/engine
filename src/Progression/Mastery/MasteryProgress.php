<?php

declare(strict_types=1);

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

  /** Null preserves a legacy history whose past purchase prices were not saved. */
  public private(set) ?int $spentPoints = null;

  /** Current spendable balance; every debit records its actual amount. */
  public int $points = 0 {
    set {
      if ($value < 0) { throw new InvalidArgumentException('A character cannot hold fewer than 0 Mastery points.'); }
      $spent = $this->spentPoints;
      if ($spent !== null && $value < $this->points) { $spent += $this->points - $value; }
      if ($spent !== null && $value > PHP_INT_MAX - $spent) {
        throw new InvalidArgumentException('Total earned Mastery points exceed the supported integer range.');
      }
      $this->spentPoints = $spent;
      $this->points = $value;
    }
  }

  /** Unspent and spent SP together, when their historical total is established. */
  public ?int $earnedPoints {
    get => $this->spentPoints === null ? null : $this->points + $this->spentPoints;
  }

  /**
   * @param int $points The Mastery points (SP) the character holds, unspent.
   * @param list<string> $learned The nodes learned.
   * @param int|null $spentPoints Historical SP spent; zero for a new character, null for unknown legacy history.
   */
  public function __construct(
    int $points = 0,
    public array $learned = [],
    ?int $spentPoints = 0,
  )
  {
    if ($spentPoints !== null && $spentPoints < 0) { throw new InvalidArgumentException('Spent Mastery points cannot be negative.'); }
    $this->spentPoints = $spentPoints;
    $this->points = $points;
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

    $spent = array_key_exists('spentPoints', $data) ? $data['spentPoints'] : ($learned === [] ? 0 : null);
    if ($spent !== null && (!is_int($spent) || $spent < 0)) {
      throw new InvalidArgumentException('Spent Mastery points must be a non-negative integer or unknown (null).');
    }
    return new self(max(0, intval($data['points'] ?? 0)), $learned, $spent);
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
    if ($points > PHP_INT_MAX - $this->points) {
      throw new InvalidArgumentException('Mastery point awards exceed the supported integer range.');
    }
    $this->points += $points;
  }

  /** Spends the actual paid price without revaluing any previously learned node. */
  public function spendPoints(int $points): void
  {
    if ($points < 0 || $points > $this->points) {
      throw new InvalidArgumentException('Mastery spending must fit the current non-negative point balance.');
    }
    $this->points -= $points;
  }

  /** @return array<string, mixed> */
  public function jsonSerialize(): array
  {
    $data = array_filter([
      'points' => $this->points,
      'learned' => $this->learned,
    ], static fn(mixed $value): bool => $value !== [] && $value !== 0);
    return $data === [] && $this->spentPoints === 0 ? [] : [...$data, 'spentPoints' => $this->spentPoints];
  }
}
