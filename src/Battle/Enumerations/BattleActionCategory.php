<?php

namespace Ichiloto\Engine\Battle\Enumerations;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\BattleTurnTimings;
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
   * A summon is whatever an authored summon links to, so its pacing follows
   * the command catalogue's action type rather than the action's name.
   *
   * @param BattleAction|null $action The action to categorize.
   * @return self
   */
  public static function fromAction(?BattleAction $action): self
  {
    if (BattleCommandCatalog::getActionType($action) === BattleCommandType::SUMMON) {
      return self::SUMMON;
    }

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
      str_contains($name, 'flare'), str_contains($name, 'ultima'), str_contains($name, 'meteor') => self::HIGH_MAGIC,
      str_contains($name, 'magic'), str_contains($name, 'spell') => self::BASIC_MAGIC,
      default => self::PHYSICAL_ATTACK,
    };
  }

  /**
   * Returns the total turn duration in seconds for the provided pace: the
   * budget the turn's phases share ({@see BattleTurnTimings::fromTotalDuration()}).
   *
   * An authored summon plays its own sequence at its own length, so its
   * budget paces only the beats around that sequence, as an attack's does.
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
        BattlePace::FAST => 1.5,
        BattlePace::MEDIUM => 2.5,
        BattlePace::SLOW => 4.0,
      },
    };
  }

  /**
   * Returns how long this category's announcement is read at the provided
   * pace when the project authors no time of its own
   * ({@see self::getAnnouncementConfigPath()}). A summon's move is held long
   * enough to read before the summon arrives; every other category reads
   * for its share of its turn budget.
   *
   * @param BattlePace $pace The active animation pace.
   * @return float
   */
  public function getAnnouncementSeconds(BattlePace $pace): float
  {
    return match ($this) {
      self::SUMMON => match ($pace) {
        BattlePace::FAST => 1.68,
        BattlePace::MEDIUM => 2.8,
        BattlePace::SLOW => 4.2,
      },
      default => $this->totalDurationSeconds($pace) * BattleTurnTimings::ANNOUNCEMENT_SHARE,
    };
  }

  /**
   * Returns the config path a project authors this category's announcement
   * reading time at, for the provided pace, in seconds.
   *
   * @param BattlePace $pace The animation pace the time applies to.
   * @return string
   */
  public function getAnnouncementConfigPath(BattlePace $pace): string
  {
    return sprintf('ui.battle.%s.%s.%s', BattlePacing::ANNOUNCEMENT_SECONDS_KEY, $this->value, $pace->value);
  }
}
