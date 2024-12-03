<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Stats;

/**
 * Represents the character interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface CharacterInterface
{
  /**
   * @var string $name The character's name.
   */
  public string $name {
    get;
  }

  /**
   * @var bool $isKnockedOut Whether the character is knocked out.
   */
  public bool $isKnockedOut {
    get;
  }

  /**
   * @var int $level The character's level.
   */
  public int $level {
    get;
  }

  /**
   * @var Stats $stats The character's stats.
   */
  public Stats $stats {
    get;
  }
}