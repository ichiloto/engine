<?php

namespace Ichiloto\Engine\Entities;

class Character
{
  public bool $isKnockedOut {
    get {
      return $this->stats->currentHp <= 0;
    }
  }

  /**
   * @var array The experience point thresholds for each level.
   */
  protected array $levelExpThresholds = [];

  protected int $currentLevel = 1;
  public int $level {
    get {
      return $this->currentLevel;
    }

    set {

    }
  }

  public function __construct(
    public protected(set) string $name,
    public protected(set) int $currentExp {
      set {
        if ($value < 0) {
          throw new \InvalidArgumentException('Experience points cannot be negative.');
        }

        $this->currentExp = $value;
      }
    },
  )
  {
    $this->calculateLevelExpThresholds();
  }

  protected function calculateLevelExpThresholds(): void
  {

  }
}