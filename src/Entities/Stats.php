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
   * @var int The total hit points.
   */
  public int $totalHp;
  /**
   * @var int The total magic points.
   */
  public int $totalMp;
  /**
   * @var int The total attack points.
   */
  public int $totalAttack;
  /**
   * @var int The total defence points.
   */
  public int $totalDefence;
  /**
   * @var int The total magic attack points.
   */
  public int $totalMagicAttack;
  /**
   * @var int The total magic defence points.
   */
  public int $totalMagicDefence;
  /**
   * @var int The total speed points.
   */
  public int $totalSpeed;
  public int $totalGrace;
  public int $totalEvasion;

  public function __construct(
    public int $currentHp {
      set {
        $this->currentHp = clamp($value, 0, self::MAX_HP);
      }
    },
    public int $currentMp {
      set {
        $this->currentMp = clamp($value, 0, self::MAX_MP);
      }
    },
    public int $attack {
      set {
        $this->attack = clamp($value, 0, self::MAX_ATK);
      }
    },
    public int $defence {
      set {
        $this->defence = clamp($value, 0, self::MAX_DEF);
      }
    },
    public int $magicAttack {
      set {
        $this->magicAttack = clamp($value, 0, self::MAX_MATK);
      }
    },
    public int $magicDefence {
      set {
        $this->magicDefence = clamp($value, 0, self::MAX_MDEF);
      }
    },
    public int $speed = self::DEFAULT_SPEED {
      set {
        $this->speed = clamp($value, 0, self::MAX_SPD);
      }
    },
    public int $grace = self::DEFAULT_GRACE {
      set {
        $this->grace = clamp($value, 0, self::MAX_GRA);
      }
    },
    public int $evasion = self::DEFAULT_EVASION {
      set {
        $this->evasion = clamp($value, 0, self::MAX_GRA);
      }
    },
  )
  {
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
      $data['evasion'] ?? self::DEFAULT_EVASION
    );
  }
}