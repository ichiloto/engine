<?php

namespace Ichiloto\Engine\Entities;

class Stats
{
  const int MAX_HP = 9999;
  const int MAX_MP = 99;
  const int MAX_ATK = 99;
  const int MAX_DEF = 99;
  const int MAX_MATK = 99;
  const int MAX_MDEF = 99;
  const int MAX_SPD = 99; // Maximum speed
  const int MAX_GRA = 99; // Maximum grace points

  public int $totalHp;
  public int $totalMp;
  public int $totalAttack;
  public int $totalDefence;
  public int $totalMagicAttack;
  public int $totalMagicDefence;
  public int $totalSpeed;
  public int $totalGrace;
  public int $totalEvasion;

  public function __construct(
    public protected(set) int $currentHp {
      set {
        $this->currentHp = max(0, min($value, self::MAX_HP));
      }
    },
    public protected(set) int $currentMp,
    public protected(set) int $attack,
    public protected(set) int $defence,
    public protected(set) int $magicAttack,
    public protected(set) int $magicDefence,
    public protected(set) int $speed = 1,
    public protected(set) int $grace = 1,
    public protected(set) int $evasion = 0,
  )
  {
  }
}