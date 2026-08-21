<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\EntityStatCapPolicy;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Entities\Stats\StatResolution;
use Ichiloto\Engine\Entities\Stats\StatResolver;

/**
 * A battler as combat math sees it.
 *
 * Wraps a battler so `$view->stats` carries equipment-adjusted values
 * (characters) with buff/debuff stage multipliers applied, while every
 * other property and method passes through to the real battler. Damage
 * formulas evaluate against views; writes (HP loss, state infliction) go
 * to the real battler.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattlerBattleView
{
  /**
   * @var Stats The stage-adjusted combat stats.
   */
  public readonly Stats $stats;

  /** @var array<string, StatResolution> Final battle resolutions, keyed by canonical stat key. */
  public readonly array $statResolutions;

  /**
   * @param object $battler The real battler (any object exposing `stats`, typically a {@see CharacterInterface}).
   */
  public function __construct(protected(set) object $battler)
  {
    $stats = clone $battler->stats;
    $caps = $battler instanceof Character
      ? EntityStatCapPolicy::player()
      : EntityStatCapPolicy::enemy();
    $persistent = $battler instanceof Character ? $battler->resolveStats() : [];
    $temporary = [];

    foreach (StatKey::cases() as $stat) {
      $property = $stat->statsProperty();
      $base = $persistent[$stat->value] ?? StatResolver::resolve(
        $stat,
        $stats->$property,
        0,
        0,
        0,
        $caps,
      );

      if (method_exists($battler, 'applyStatStage')
        && method_exists($battler, 'buffableStats')
        && in_array($property, $battler::buffableStats(), true)) {
        $temporary[$stat->value] = $battler->applyStatStage($property, $base->uncappedValue)
          - $base->uncappedValue;
      }
    }

    $resolutions = $battler instanceof Character
      ? $battler->resolveStats(temporary: $temporary)
      : [];

    foreach (StatKey::cases() as $stat) {
      $property = $stat->statsProperty();

      if (! isset($resolutions[$stat->value])) {
        $resolutions[$stat->value] = StatResolver::resolve(
          $stat,
          $stats->$property,
          0,
          0,
          0,
          $caps,
          $temporary[$stat->value] ?? 0,
        );
      }

      $stats->$property = $resolutions[$stat->value]->effectiveValue;
    }

    $this->stats = $stats;
    $this->statResolutions = $resolutions;
  }

  /** Returns inspectable final-layer metadata for one battle stat. */
  public function resolveStat(StatKey|string $stat): StatResolution
  {
    $key = is_string($stat) ? StatKey::require($stat) : $stat;

    return $this->statResolutions[$key->value];
  }

  /**
   * Passes property reads through to the real battler.
   *
   * @param string $name The property name.
   * @return mixed The battler's value.
   */
  public function __get(string $name): mixed
  {
    return $this->battler->$name;
  }

  /**
   * Passes method calls through to the real battler.
   *
   * @param string $method The method name.
   * @param array $arguments The arguments.
   * @return mixed The battler's return value.
   */
  public function __call(string $method, array $arguments): mixed
  {
    return $this->battler->$method(...$arguments);
  }
}
