<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Stats;
use JsonSerializable;
use Serializable;

/**
 * Represents the character interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface CharacterInterface extends CanUseItem, JsonSerializable, Serializable
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
   * @var bool $isFighting Whether the battler still takes part in a battle:
   *   it acts, can be targeted and keeps its side from defeat. A knocked-out
   *   battler, or an enemy that has conceded, is not fighting.
   */
  public bool $isFighting {
    get;
  }

  /**
   * @var int $knockoutFloor The HP that battle damage cannot take this
   *   battler below; 0 lets damage knock it out.
   */
  public int $knockoutFloor {
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

  /**
   * Returns the character as an array.
   *
   * @return array<string, mixed> Returns the character as an array.
   */
  public function toArray(): array;
}