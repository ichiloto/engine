<?php

namespace Ichiloto\Engine\Battle\Simulation;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

/**
 * Fights a battle over and over, with nobody watching.
 *
 * Balancing a fight by playing it tells you how one run went. Fighting it a
 * thousand times tells you what the fight *is*: whether it is winnable, how
 * long it drags, and which party member keeps dying. The combat itself is the
 * game's own, so the numbers this reports are the numbers a player will meet.
 *
 * Both sides simply attack here. A designer wants the shape of a fight before
 * tactics, and tactics are what a player brings to change that shape.
 *
 * @package Ichiloto\Engine\Battle\Simulation
 */
class BattleSimulator
{
  /**
   * How many turns a battle may run before it is called a stalemate.
   */
  public const int DEFAULT_TURN_LIMIT = 50;

  /**
   * @param int $turnLimit How long a battle may run before it is abandoned.
   */
  public function __construct(
    protected int $turnLimit = self::DEFAULT_TURN_LIMIT,
    protected int $seed = 1,
  )
  {
  }

  /**
   * Fights the troop repeatedly and reports what happened.
   *
   * The party and troop are restored to full between runs, so every battle is
   * the one a player walks into rather than the one after the last.
   *
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param int $runs How many battles to fight.
   * @return SimulationReport What happened.
   */
  public function simulate(Party $party, Troop $troop, int $runs = 100): SimulationReport
  {
    $runs = max(1, $runs);
    $allies = $this->battlersOf($party->battlers->toArray());
    $enemies = $this->battlersOf($troop->members->toArray());

    $fullHealth = [
      ...$this->healthOf($allies),
      ...$this->healthOf($enemies),
    ];

    $victories = 0;
    $defeats = 0;
    $stalemates = 0;
    $totalTurns = 0;
    $totalHpShare = 0.0;
    $deaths = [];
    $damage = [];
    $hpLoss = [];
    $healing = [];
    $mitigation = [];
    $random = new SeededCombatRandomSource($this->seed);

    for ($run = 0; $run < $runs; $run++) {
      $this->restore([...$allies, ...$enemies], $fullHealth);

      $outcome = $this->fight($allies, $enemies, $damage, $hpLoss, $healing, $mitigation, $random);
      $totalTurns += $outcome['turns'];

      match ($outcome['result']) {
        'victory' => $victories++,
        'defeat' => $defeats++,
        default => $stalemates++,
      };

      if ($outcome['result'] === 'victory') {
        $totalHpShare += $this->healthShare($allies, $fullHealth);
      }

      foreach ($allies as $ally) {
        if ($ally->stats->currentHp <= 0) {
          $deaths[$ally->name] = ($deaths[$ally->name] ?? 0) + 1;
        }
      }
    }

    // Leave the party as the caller handed it over.
    $this->restore([...$allies, ...$enemies], $fullHealth);

    return new SimulationReport(
      $troop->name,
      $runs,
      $victories,
      $defeats,
      $stalemates,
      $totalTurns / $runs,
      $victories > 0 ? $totalHpShare / $victories : 0.0,
      $deaths,
      array_map(static fn(float $total): float => $total / $runs, $damage),
      $this->seed,
      array_map(static fn(float $total): float => $total / $runs, $hpLoss),
      array_map(static fn(float $total): float => $total / $runs, $healing),
      array_map(static fn(float $total): float => $total / $runs, $mitigation),
    );
  }

  /** Seeded preview seam proving simulator/live parity at the action boundary. */
  public function previewAttack(
    CharacterInterface $actor,
    CharacterInterface $target,
    ?int $seed = null,
  ): CombatActionResult
  {
    $action = new AttackAction(
      'Attack',
      new CombatResolver(),
      new SeededCombatRandomSource($seed ?? $this->seed),
    );
    $action->execute($actor, [$target]);

    return $action->lastResult ?? new CombatActionResult(
      'attack',
      'attack:0',
      CombatResolver::identity($actor),
      [],
    );
  }

  /**
   * Fights one battle.
   *
   * @param CharacterInterface[] $allies The party.
   * @param CharacterInterface[] $enemies The troop.
   * @param array<string, float> $damage Running damage totals, by name.
   * @return array{result: string, turns: int} How it went.
   */
  protected function fight(
    array $allies,
    array $enemies,
    array &$damage,
    array &$hpLoss,
    array &$healing,
    array &$mitigation,
    SeededCombatRandomSource $random,
  ): array
  {
    $attack = new AttackAction('Attack', new CombatResolver(), $random);
    $turns = 0;

    while ($turns < $this->turnLimit) {
      $turns++;

      // Faster battlers act first, as they do in a real turn.
      $order = [...$allies, ...$enemies];
      usort(
        $order,
        static fn(CharacterInterface $a, CharacterInterface $b): int => $b->stats->speed <=> $a->stats->speed
      );

      foreach ($order as $battler) {
        if ($battler->stats->currentHp <= 0) {
          continue;
        }

        $isAlly = in_array($battler, $allies, true);
        $targets = $this->living($isAlly ? $enemies : $allies);

        if ($targets === []) {
          break;
        }

        $target = $targets[$random->nextInt(0, count($targets) - 1)];
        $attack->execute($battler, [$target]);
        $result = $attack->lastResult;

        if ($result === null) {
          continue;
        }

        if ($isAlly) {
          $damage[$battler->name] = ($damage[$battler->name] ?? 0.0) + $result->actualHpLost();
        }

        $hpLoss[$target->name] = ($hpLoss[$target->name] ?? 0.0) + $result->actualHpLost();
        $healing[$battler->name] = ($healing[$battler->name] ?? 0.0) + $result->actualHpRestored();
        $mitigation[$target->name] = ($mitigation[$target->name] ?? 0.0) + $result->mitigation();
      }

      if ($this->living($enemies) === []) {
        return ['result' => 'victory', 'turns' => $turns];
      }

      if ($this->living($allies) === []) {
        return ['result' => 'defeat', 'turns' => $turns];
      }
    }

    return ['result' => 'stalemate', 'turns' => $turns];
  }

  /**
   * Returns the battlers still standing.
   *
   * @param CharacterInterface[] $battlers The battlers.
   * @return CharacterInterface[] The living ones.
   */
  protected function living(array $battlers): array
  {
    return array_values(array_filter(
      $battlers,
      static fn(CharacterInterface $battler): bool => $battler->stats->currentHp > 0
    ));
  }

  /**
   * Filters a group down to things that can actually fight.
   *
   * @param array<int, mixed> $members The group's members.
   * @return CharacterInterface[] The battlers.
   */
  protected function battlersOf(array $members): array
  {
    return array_values(array_filter(
      $members,
      static fn(mixed $member): bool => $member instanceof CharacterInterface
    ));
  }

  /**
   * Records everyone's full health.
   *
   * @param CharacterInterface[] $battlers The battlers.
   * @return array<int, array{0: int, 1: int}> The hp and mp, by battler position.
   */
  protected function healthOf(array $battlers): array
  {
    $health = [];

    foreach ($battlers as $battler) {
      $health[spl_object_id($battler)] = [$battler->stats->currentHp, $battler->stats->currentMp];
    }

    return $health;
  }

  /**
   * Puts everyone back to the health they started at.
   *
   * @param CharacterInterface[] $battlers The battlers.
   * @param array<int, array{0: int, 1: int}> $health The recorded health.
   * @return void
   */
  protected function restore(array $battlers, array $health): void
  {
    foreach ($battlers as $battler) {
      [$hp, $mp] = $health[spl_object_id($battler)] ?? [$battler->stats->totalHp, $battler->stats->totalMp];

      $battler->stats->currentHp = $hp;
      $battler->stats->currentMp = $mp;
    }
  }

  /**
   * Returns how much of the party's health is left.
   *
   * @param CharacterInterface[] $allies The party.
   * @param array<int, array{0: int, 1: int}> $health The health they started at.
   * @return float The share remaining, from 0.0 to 1.0.
   */
  protected function healthShare(array $allies, array $health): float
  {
    $remaining = 0;
    $total = 0;

    foreach ($allies as $ally) {
      $remaining += max(0, $ally->stats->currentHp);
      $total += $health[spl_object_id($ally)][0] ?? $ally->stats->totalHp;
    }

    return $total > 0 ? $remaining / $total : 0.0;
  }
}
