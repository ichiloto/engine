<?php

namespace Ichiloto\Engine\Progress;

/**
 * Tracks which enemies the party has met and defeated.
 *
 * Pure serializable state that rides the save file; the codex screen reads
 * it to decide which entries to reveal.
 *
 * @package Ichiloto\Engine\Progress
 */
class Bestiary
{
  /**
   * @var array<string, int> Encounter counts, keyed by enemy name.
   */
  protected(set) array $seen = [];
  /**
   * @var array<string, int> Defeat counts, keyed by enemy name.
   */
  protected(set) array $defeated = [];

  /**
   * Records that an enemy was encountered.
   *
   * @param string $enemyName The enemy's name.
   * @return bool True when this is the first sighting.
   */
  public function recordSeen(string $enemyName): bool
  {
    $enemyName = trim($enemyName);

    if ($enemyName === '') {
      return false;
    }

    $isFirst = ! isset($this->seen[$enemyName]);
    $this->seen[$enemyName] = ($this->seen[$enemyName] ?? 0) + 1;

    return $isFirst;
  }

  /**
   * Records that an enemy was defeated (which also counts as seen).
   *
   * @param string $enemyName The enemy's name.
   * @return bool True when this is the first defeat.
   */
  public function recordDefeated(string $enemyName): bool
  {
    $enemyName = trim($enemyName);

    if ($enemyName === '') {
      return false;
    }

    if (! isset($this->seen[$enemyName])) {
      $this->recordSeen($enemyName);
    }

    $isFirst = ! isset($this->defeated[$enemyName]);
    $this->defeated[$enemyName] = ($this->defeated[$enemyName] ?? 0) + 1;

    return $isFirst;
  }

  /**
   * Determines whether an enemy has been encountered.
   *
   * @param string $enemyName The enemy's name.
   * @return bool True when seen at least once.
   */
  public function hasSeen(string $enemyName): bool
  {
    return isset($this->seen[trim($enemyName)]);
  }

  /**
   * Returns how many times an enemy has been encountered.
   *
   * @param string $enemyName The enemy's name.
   * @return int The encounter count.
   */
  public function timesSeen(string $enemyName): int
  {
    return $this->seen[trim($enemyName)] ?? 0;
  }

  /**
   * Returns how many times an enemy has been defeated.
   *
   * @param string $enemyName The enemy's name.
   * @return int The defeat count.
   */
  public function timesDefeated(string $enemyName): int
  {
    return $this->defeated[trim($enemyName)] ?? 0;
  }

  /**
   * Returns the number of distinct enemies encountered.
   *
   * @return int The discovered count.
   */
  public function discoveredCount(): int
  {
    return count($this->seen);
  }

  /**
   * @return array{seen: array<string, int>, defeated: array<string, int>}
   */
  public function toArray(): array
  {
    return [
      'seen' => $this->seen,
      'defeated' => $this->defeated,
    ];
  }

  /**
   * @param array<string, mixed> $data The persisted bestiary data.
   * @return self The restored bestiary.
   */
  public static function fromArray(array $data): self
  {
    $bestiary = new self();

    foreach (['seen', 'defeated'] as $bucket) {
      foreach ((array) ($data[$bucket] ?? []) as $enemyName => $count) {
        if (is_string($enemyName) && trim($enemyName) !== '') {
          $bestiary->$bucket[trim($enemyName)] = max(1, intval($count));
        }
      }
    }

    return $bestiary;
  }
}
