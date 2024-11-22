<?php

namespace Ichiloto\Engine\Entities;

/**
 * The Character class.
 *
 * @package Ichiloto\Engine\Entities
 */
class Character
{
  const int MAX_LEVEL = 100;

  public bool $isKnockedOut {
    get {
      return $this->stats->currentHp > 0;
    }
  }

  /**
   * @var array The experience point thresholds for each level.
   */
  protected array $levelExpThresholds = [];

  /**
   * @var int The character's level.
   */
  public int $level {
    get {
      foreach ($this->levelExpThresholds as $level => $expThreshold) {
        if ($this->currentExp < $expThreshold) {
          return $level - 1;
        }
      }

      return self::MAX_LEVEL;
    }
  }

  /**
   * @var int The experience points required to reach the next level.
   */
  public int $nextLevelExp {
    get {
      # If maxed out, return 0.
      if ($this->level === self::MAX_LEVEL) {
        return 0;
      }

      $nextLevelExp = $this->levelExpThresholds[$this->level + 1] ?? 0;
      return max(0, $nextLevelExp - $this->currentExp);
    }
  }

  /**
   * Character constructor.
   *
   * @param string $name The character's name.
   * @param int $currentExp The character's current experience points.
   * @param Stats $stats The character's stats.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) int $currentExp {
      set {
        if ($value < 0) {
          throw new \InvalidArgumentException('Experience points cannot be negative.');
        }

        $this->currentExp = $value;
      }
    },
    protected(set) Stats $stats
  )
  {
    $this->calculateLevelExpThresholds();
  }

  /**
   * Calculates the experience point thresholds for each level.
   *
   * @return void
   */
  protected function calculateLevelExpThresholds(): void
  {
    for ($level = 0; $level <= self::MAX_LEVEL; $level++) {
      $this->levelExpThresholds[$level] = $level === 1 ? 0 : pow($level - 1, 2) * 100;
    }
  }
}