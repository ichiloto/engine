<?php

namespace Ichiloto\Engine\Battle\Simulation;

use Ichiloto\Engine\Battle\BattlePartyRoster;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\CounterAttackResolver;
use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Battle\InherentAttack;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Battle\ReservePolicy;
use Ichiloto\Engine\Battle\BattlerBattleView;
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
 * Each side acts as it would with nobody choosing for it. A party member uses
 * its inherent attack (its authored attackSkill, or the built-in attack), and
 * an enemy chooses from its authored action patterns exactly as it does in
 * play. A designer wants the shape of a fight before tactics, and tactics are
 * what a player brings to change that shape. Each turn keeps the live turn's
 * rules: a guard ends when its battler acts again, a blocking state consumes
 * the turn, recipients are revalidated against the action's scope, counters
 * answer landed hits, and states tick when the battler's turn ends.
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
    private readonly ?SkillCatalog $skills = null,
  )
  {
  }

  /**
   * Fights the troop repeatedly and reports what happened.
   *
   * Each run uses independent battler instances from the caller's starting
   * state. Damage, costs, afflictions and exceptions cannot alter the live party.
   *
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param int $runs How many battles to fight.
   * @param array<string, mixed> $settings Per-battle settings, including reservePolicy.
   * @return SimulationReport What happened.
   */
  public function simulate(Party $party, Troop $troop, int $runs = 100, array $settings = []): SimulationReport
  {
    $runs = max(1, $runs);
    $policy = ReservePolicy::resolve($settings['reservePolicy'] ?? null);
    $allies = $this->battlersOf($party->members->toArray());
    $enemies = $this->battlersOf($troop->members->toArray());

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
    new CounterAttackResolver($this->skills, $random)->validateBattlers([...$allies, ...$enemies]);

    for ($run = 0; $run < $runs; $run++) {
      $runParty = new Party();
      foreach ($allies as $ally) { $runParty->addMember(clone $ally); }
      $runEnemies = array_map(static fn(CharacterInterface $enemy): CharacterInterface => clone $enemy, $enemies);
      $runAllies = $runParty->members->toArray();
      $fullHealth = $this->healthOf([...$runAllies, ...$runEnemies]);
      $roster = new BattlePartyRoster($runParty, $policy);
      $outcome = $this->fight($roster, $runEnemies, $damage, $hpLoss, $healing, $mitigation, $random);
      $totalTurns += $outcome['turns'];

      match ($outcome['result']) {
        'victory' => $victories++,
        'defeat' => $defeats++,
        default => $stalemates++,
      };

      if ($outcome['result'] === 'victory') {
        $totalHpShare += $this->healthShare($roster->battlers, $fullHealth);
      }

      foreach ($runAllies as $ally) {
        if ($ally->stats->currentHp <= 0) {
          $deaths[$ally->name] = ($deaths[$ally->name] ?? 0) + 1;
        }
      }
    }

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

  /**
   * Seeded preview seam proving simulator/live parity at the action boundary:
   * one inherent attack, the same one a simulated run makes.
   */
  public function previewAttack(
    CharacterInterface $actor,
    CharacterInterface $target,
    ?int $seed = null,
  ): CombatActionResult
  {
    $action = InherentAttack::createAction(
      $actor,
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
   * @param BattlePartyRoster $roster The battle's active party.
   * @param CharacterInterface[] $enemies The troop.
   * @param array<string, float> $damage Running damage totals, by name.
   * @return array{result: string, turns: int} How it went.
   */
  protected function fight(
    BattlePartyRoster $roster,
    array $enemies,
    array &$damage,
    array &$hpLoss,
    array &$healing,
    array &$mitigation,
    SeededCombatRandomSource $random,
  ): array
  {
    $resolver = new CombatResolver();
    $counters = new CounterAttackResolver($this->skills, $random);
    $turns = 0;

    while ($turns < $this->turnLimit) {
      if ($roster->battlers === [] || ($roster->isDefeated && !$roster->promoteReservesAfterWipeout())) {
        return ['result' => 'defeat', 'turns' => $turns];
      }
      $allies = $roster->battlers;
      $turns++;

      // Faster battlers act first, as they do in a real turn.
      $order = [...$allies, ...$enemies];
      $battleSpeed = [];

      foreach ($order as $battler) {
        $battleSpeed[spl_object_id($battler)] = new BattlerBattleView($battler)->stats->speed;
      }

      usort(
        $order,
        static fn(CharacterInterface $a, CharacterInterface $b): int =>
          $battleSpeed[spl_object_id($b)] <=> $battleSpeed[spl_object_id($a)]
      );

      foreach ($order as $battler) {
        if ($battler->stats->currentHp <= 0) {
          continue;
        }

        $isAlly = in_array($battler, $allies, true);

        if ($this->living($isAlly ? $enemies : $allies) === []) {
          break;
        }

        // A guard raised last round protects until this battler acts again.
        if (method_exists($battler, 'stopGuarding')) {
          $battler->stopGuarding();
        }

        // A state like sleep or paralysis consumes the turn outright.
        $blocked = method_exists($battler, 'getActionBlockingState') && $battler->getActionBlockingState() !== null;

        if (! $blocked) {
          [$action, $chosen] = $isAlly
            ? [InherentAttack::createAction($battler, $resolver, $random), []]
            : EnemyActionEvaluator::chooseAction(
              $battler, $allies, $enemies, $turns, $this->highestLevelOf($allies), random: $random);
          $targets = BattleTargetPolicy::resolveTargets(
            $action->targetScope,
            $battler,
            $isAlly ? $allies : $enemies,
            $isAlly ? $enemies : $allies,
            $chosen === [] && $isAlly ? $this->pickRandom($this->living($enemies), $random) : $chosen,
            $random,
          );

          if ($targets !== []) {
            $action->execute($battler, $targets);

            if (($result = $action->lastResult) !== null) {
              $this->recordResult($result, $battler, $targets, $isAlly, $damage, $hpLoss, $healing, $mitigation);
              foreach ($counters->resolveResponses($result, $battler, $targets, $allies, $enemies) as $response) {
                if (!$counters->canExecute($response, $allies, $enemies)) { continue; }
                $response->action->execute($response->actor, [$response->target]);
                if (($counterResult = $response->action->lastResult) !== null) {
                  $this->recordResult($counterResult, $response->actor, [$response->target], !$isAlly,
                    $damage, $hpLoss, $healing, $mitigation);
                }
              }
            }
          }
        }

        // State duration and damage belong to the battler's completed turn.
        if ($battler->stats->currentHp > 0 && method_exists($battler, 'tickStates')) {
          $before = $battler->stats->currentHp;
          $battler->tickStates();
          if (($loss = $before - $battler->stats->currentHp) > 0) {
            $hpLoss[$battler->name] = ($hpLoss[$battler->name] ?? 0.0) + $loss;
          }
        }
      }

      if ($this->living($enemies) === []) {
        return ['result' => 'victory', 'turns' => $turns];
      }

      if ($roster->isDefeated && !$roster->promoteReservesAfterWipeout()) {
        return ['result' => 'defeat', 'turns' => $turns];
      }
    }

    return ['result' => 'stalemate', 'turns' => $turns];
  }

  /**
   * @param CharacterInterface[] $targets The action's recipients.
   */
  private function recordResult(CombatActionResult $result, CharacterInterface $actor, array $targets,
    bool $isAlly, array &$damage, array &$hpLoss, array &$healing, array &$mitigation): void
  {
    if ($isAlly) { $damage[$actor->name] = ($damage[$actor->name] ?? 0.0) + $result->actualHpLost(); }
    $healing[$actor->name] = ($healing[$actor->name] ?? 0.0) + $result->actualHpRestored();
    $names = [];
    foreach ($targets as $target) { $names[CombatResolver::identity($target)] = $target->name; }
    foreach ($result->targets as $outcome) {
      $name = $names[$outcome->targetId] ?? null;
      if ($name === null) { continue; }
      $hpLoss[$name] = ($hpLoss[$name] ?? 0.0) + $outcome->actualHpLost();
      $mitigation[$name] = ($mitigation[$name] ?? 0.0) + $outcome->mitigation();
    }
  }

  /**
   * Returns one battler picked at random, as a party member picks its target.
   *
   * @param CharacterInterface[] $battlers The candidates.
   * @return CharacterInterface[] The pick, or nothing when there are no candidates.
   */
  private function pickRandom(array $battlers, SeededCombatRandomSource $random): array
  {
    return $battlers === [] ? [] : [$battlers[$random->nextInt(0, count($battlers) - 1)]];
  }

  /**
   * Returns the highest level in the party, which enemy party-level conditions read.
   *
   * @param CharacterInterface[] $allies The active party.
   */
  private function highestLevelOf(array $allies): int
  {
    $level = 1;
    foreach ($allies as $ally) {
      if ($ally instanceof Character) { $level = max($level, $ally->level); }
    }
    return $level;
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
      // The simulator runs no battle events. A knockout floor exists so an event can end the
      // fight, so a battler held at its floor has finished its part, as it would in play.
      static fn(CharacterInterface $battler): bool => $battler->isFighting
        && ($battler->knockoutFloor === 0 || $battler->stats->currentHp > $battler->knockoutFloor)
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
