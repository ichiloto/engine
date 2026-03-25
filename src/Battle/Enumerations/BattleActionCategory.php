<?php

namespace Ichiloto\Engine\Battle\Enumerations;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Skills\MagicSkill;

/**
 * Represents the timing category used for battle turn pacing.
 *
 * @package Ichiloto\Engine\Battle\Enumerations
 */
enum BattleActionCategory: string
{
  case PHYSICAL_ATTACK = 'physical_attack';
  case BASIC_MAGIC = 'basic_magic';
  case HIGH_MAGIC = 'high_magic';
  case SUMMON = 'summon';

  /**
   * Infers the action timing category from the given battle action.
   *
   * @param BattleAction|null $action The action to categorize.
   * @return self
   */
  public static function fromAction(?BattleAction $action): self
  {
    if ($action instanceof AttackAction || $action === null) {
      return self::PHYSICAL_ATTACK;
    }

    if ($action instanceof SkillBattleAction) {
      if ($action->skill instanceof MagicSkill) {
        $name = strtolower($action->name);

        return match (true) {
          str_contains($name, 'flare'), str_contains($name, 'ultima'), str_contains($name, 'meteor') => self::HIGH_MAGIC,
          default => self::BASIC_MAGIC,
        };
      }
    }

    $name = strtolower($action->name);

    return match (true) {
      str_contains($name, 'summon'), str_contains($name, 'esper') => self::SUMMON,
      str_contains($name, 'flare'), str_contains($name, 'ultima'), str_contains($name, 'meteor') => self::HIGH_MAGIC,
      str_contains($name, 'magic'), str_contains($name, 'spell') => self::BASIC_MAGIC,
      default => self::PHYSICAL_ATTACK,
    };
  }

  /**
   * Returns the total turn duration in seconds for the provided pace.
   *
   * @param BattlePace $pace The active animation pace.
   * @return float
   */
  public function totalDurationSeconds(BattlePace $pace): float
  {
    return match ($this) {
      self::PHYSICAL_ATTACK => match ($pace) {
        BattlePace::FAST => 1.5,
        BattlePace::MEDIUM => 2.5,
        BattlePace::SLOW => 4.0,
      },
      self::BASIC_MAGIC => match ($pace) {
        BattlePace::FAST => 2.0,
        BattlePace::MEDIUM => 3.5,
        BattlePace::SLOW => 5.5,
      },
      self::HIGH_MAGIC => match ($pace) {
        BattlePace::FAST => 4.0,
        BattlePace::MEDIUM => 6.0,
        BattlePace::SLOW => 9.0,
      },
      self::SUMMON => match ($pace) {
        BattlePace::FAST => 6.0,
        BattlePace::MEDIUM => 10.0,
        BattlePace::SLOW => 15.0,
      },
    };
  }
}
