<?php

namespace Ichiloto\Engine\Battle\Simulation;

/**
 * What a run of simulated battles came to.
 *
 * @package Ichiloto\Engine\Battle\Simulation
 */
final readonly class SimulationReport
{
  /**
   * @param string $troop The troop fought.
   * @param int $runs How many battles were fought.
   * @param int $victories How many the party won.
   * @param int $defeats How many wiped the party.
   * @param int $stalemates How many hit the turn limit with both sides alive.
   * @param float $averageTurns The average length of a battle, in turns.
   * @param float $averageHpRemaining The share of party HP left after a win, from 0.0 to 1.0.
   * @param array<string, int> $deaths How often each party member fell, by name.
   * @param array<string, float> $damageDealt The average damage each party member dealt.
   */
  public function __construct(
    public string $troop,
    public int $runs,
    public int $victories,
    public int $defeats,
    public int $stalemates,
    public float $averageTurns,
    public float $averageHpRemaining,
    public array $deaths = [],
    public array $damageDealt = [],
  )
  {
  }

  /**
   * Returns the share of battles the party won.
   *
   * @return float The win rate, from 0.0 to 1.0.
   */
  public function winRate(): float
  {
    return $this->runs > 0 ? $this->victories / $this->runs : 0.0;
  }

  /**
   * Describes how the fight sits, in the terms a designer balances in.
   *
   * @return string The verdict.
   */
  public function verdict(): string
  {
    $winRate = $this->winRate();

    return match (true) {
      $this->stalemates > $this->runs / 2 => 'a slog: most battles hit the turn limit',
      $winRate >= 0.98 && $this->averageHpRemaining >= 0.8 => 'trivial',
      $winRate >= 0.9 => 'comfortable',
      $winRate >= 0.6 => 'a real fight',
      $winRate >= 0.25 => 'punishing',
      default => 'a wall: the party loses most of the time',
    };
  }
}
