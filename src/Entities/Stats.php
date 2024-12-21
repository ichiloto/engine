<?php

namespace Ichiloto\Engine\Entities;

use InvalidArgumentException;

/**
 * The Stats class.
 *
 * @package Ichiloto\Engine\Entities
 */
class Stats
{
  /**
   * The maximum hit points.
   */
  const int MAX_HP = 9999;
  /**
   * The maximum magic points.
   */
  const int MAX_MP = 99;
  /**
   * The maximum attack points.
   */
  const int MAX_ATK = 99;
  /**
   * The maximum defence points.
   */
  const int MAX_DEF = 99;
  /**
   * The maximum magic attack points.
   */
  const int MAX_MATK = 99;
  /**
   * The maximum magic defence points.
   */
  const int MAX_MDEF = 99;
  /**
   * The maximum speed points.
   */
  const int MAX_SPD = 99;
  /**
   * The maximum grace points.
   */
  const int MAX_GRA = 99;
  /**
   * The default HP.
   */
  const int DEFAULT_CURRENT_HP = 100;
  /**
   * The default MP.
   */
  const int DEFAULT_CURRENT_MP = 10;
  /**
   * The default attack points.
   */
  const int DEFAULT_ATTACK = 5;
  /**
   * The default defence points.
   */
  const int DEFAULT_DEFENCE = 5;
  /**
   * The default magic attack points.
   */
  const int DEFAULT_MAGIC_ATTACK = 5;
  /**
   * The default magic defence points.
   */
  const int DEFAULT_MAGIC_DEFENCE = 5;
  /**
   * The maximum evasion points.
   */
  const int DEFAULT_SPEED = 1;
  /**
   * The default grace points.
   */
  const int DEFAULT_GRACE = 1;
  /**
   * The default evasion points.
   */
  const int DEFAULT_EVASION = 0;
  /**
   * @var int The current hit points.
   */
  public int $currentHp = self::DEFAULT_CURRENT_HP {
    set {
      $this->currentHp = $value;
      if ($this->currentHp < 0) {
        $this->currentHp = 0;
      }
      if ($this->currentHp > $this->totalHp) {
        $this->currentHp = $this->totalHp;
      }
    }
  }
  /**
   * @var int The current magic points.
   */
  public int $currentMp = self::DEFAULT_CURRENT_MP {
    set {
      $this->currentMp = clamp($value, 0, $this->totalMp);
    }
  }
  /**
   * @var int The attack points.
   */
  public int $attack = self::DEFAULT_ATTACK {
    set {
      $this->attack = clamp($value, 0, $this->totalAttack);
    }
  }
  /**
   * @var int The defence points.
   */
  public int $defence = self::DEFAULT_DEFENCE {
    set {
      $this->defence = clamp($value, 0, $this->totalDefence);
    }
  }
  /**
   * @var int The magic attack points.
   */
  public int $magicAttack = self::DEFAULT_MAGIC_ATTACK {
    set {
      $this->magicAttack = clamp($value, 0, $this->totalMagicAttack);
    }
  }
  /**
   * @var int The magic defence points.
   */
  public int $magicDefence = self::DEFAULT_MAGIC_DEFENCE {
    set {
      $this->magicDefence = clamp($value, 0, $this->totalMagicDefence);
    }
  }
  /**
   * @var int The speed points.
   */
  public int $speed = self::DEFAULT_SPEED {
    set {
      $this->speed = clamp($value, 0, $this->totalSpeed);
    }
  }
  /**
   * @var int The grace points.
   */
  public int $grace = self::DEFAULT_GRACE {
    set {
      $this->grace = clamp($value, 0, $this->totalGrace);
    }
  }
  /**
   * @var int The evasion points.
   */
  public int $evasion = self::DEFAULT_EVASION {
    set {
      $this->evasion = clamp($value, 0, $this->totalEvasion);
    }
  }
  /**
   * @var int The total hit points.
   */
  public int $totalHp = 0;
  /**
   * @var int The total magic points.
   */
  public int $totalMp = 0;
  /**
   * @var int The total attack points.
   */
  public int $totalAttack = 0;
  /**
   * @var int The total defence points.
   */
  public int $totalDefence = 0;
  /**
   * @var int The total magic attack points.
   */
  public int $totalMagicAttack = 0;
  /**
   * @var int The total magic defence points.
   */
  public int $totalMagicDefence = 0;
  /**
   * @var int The total speed points.
   */
  public int $totalSpeed = self::DEFAULT_SPEED;
  /**
   * @var int The total grace points.
   */
  public int $totalGrace = self::DEFAULT_GRACE;
  /**
   * @var int The total evasion points.
   */
  public int $totalEvasion = self::DEFAULT_EVASION;

  /**
   * Stats constructor.
   *
   * @param int $currentHp The current hit points.
   * @param int $currentMp The current magic points.
   * @param int $attack The attack points.
   * @param int $defence The defence points.
   * @param int $magicAttack The magic attack points.
   * @param int $magicDefence The magic defence points.
   * @param int $speed The speed points.
   * @param int $grace The grace points.
   * @param int $evasion The evasion points.
   * @param int|null $totalHp The total hit points.
   * @param int|null $totalMp The total magic points.
   * @param int|null $totalAttack The total attack points.
   * @param int|null $totalDefence The total defence points.
   * @param int|null $totalMagicAttack The total magic attack points.
   * @param int|null $totalMagicDefence The total magic defence points.
   * @param int|null $totalSpeed The total speed points.
   * @param int|null $totalGrace The total grace points.
   * @param int|null $totalEvasion The total evasion points.
   */
  public function __construct(
    int $currentHp = self::DEFAULT_CURRENT_HP,
    int $currentMp = self::DEFAULT_CURRENT_MP,
    int $attack = self::DEFAULT_ATTACK,
    int $defence = self::DEFAULT_DEFENCE,
    int $magicAttack = self::DEFAULT_MAGIC_ATTACK,
    int $magicDefence = self::DEFAULT_MAGIC_DEFENCE,
    int $speed = self::DEFAULT_SPEED,
    int $grace = self::DEFAULT_GRACE,
    int $evasion = self::DEFAULT_EVASION,
    ?int $totalHp = null,
    ?int $totalMp = null,
    ?int $totalAttack = null,
    ?int $totalDefence = null,
    ?int $totalMagicAttack = null,
    ?int $totalMagicDefence = null,
    ?int $totalSpeed = null,
    ?int $totalGrace = null,
    ?int $totalEvasion = null
  )
  {
    $this->totalHp = $totalHp ?? $currentHp;
    $this->totalMp = $totalMp ?? $currentMp;
    $this->totalAttack = $totalAttack ?? $attack;
    $this->totalDefence = $totalDefence ?? $defence;
    $this->totalMagicAttack = $totalMagicAttack ?? $magicAttack;
    $this->totalMagicDefence = $totalMagicDefence ?? $magicDefence;
    $this->totalSpeed = $totalSpeed ?? $speed;
    $this->totalGrace = $totalGrace ?? $grace;
    $this->totalEvasion = $totalEvasion ?? $evasion;

    // Initialize main stats after total stats have been set to avoid clamping issues.
    $this->currentHp = $currentHp;
    $this->currentMp = $currentMp;
    $this->attack = $attack;
    $this->defence = $defence;
    $this->magicAttack = $magicAttack;
    $this->magicDefence = $magicDefence;
    $this->speed = $speed;
    $this->grace = $grace;
    $this->evasion = $evasion;
  }

  /**
   * Creates a new instance of Stats from an array.
   *
   * @param array $data The data to create the instance from.
   * @return self The new instance.
   */
  public static function fromArray(array $data): self
  {
    return new self(
      $data['currentHp'] ?? throw new InvalidArgumentException('Current hit points are required.'),
      $data['currentMp'] ?? throw new InvalidArgumentException('Current magic points are required.'),
      $data['attack'] ?? throw new InvalidArgumentException('Attack points are required.'),
      $data['defence'] ?? throw new InvalidArgumentException('Defence points are required.'),
      $data['magicAttack'] ?? throw new InvalidArgumentException('Magic attack points are required.'),
      $data['magicDefence'] ?? throw new InvalidArgumentException('Magic defence points are required.'),
      $data['speed'] ?? self::DEFAULT_SPEED,
      $data['grace'] ?? self::DEFAULT_GRACE,
      $data['evasion'] ?? self::DEFAULT_EVASION,
      $data['totalHp'] ?? null,
      $data['totalMp'] ?? null,
      $data['totalAttack'] ?? null,
      $data['totalDefence'] ?? null,
      $data['totalMagicAttack'] ?? null,
      $data['totalMagicDefence'] ?? null,
      $data['totalSpeed'] ?? null,
      $data['totalGrace'] ?? null,
      $data['totalEvasion'] ?? null
    );
  }
}