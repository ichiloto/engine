<?php

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use InvalidArgumentException;

/**
 * The single progression path for battle, quest, script, and migration EXP.
 */
final class ExperienceAwarder
{
  private function __construct()
  {
  }

  public static function award(Character $character, int $experience): ExperienceAwardResult
  {
    if ($experience < 0) {
      throw new InvalidArgumentException('Experience awards cannot be negative.');
    }

    $oldLevel = $character->level;
    $character->addExperience($experience);
    $newLevel = $character->level;

    // Level recovery is part of progression, not generic stat recalculation.
    // Keeping it here makes battle, quest, and scripted EXP agree while
    // equipment changes and save hydration continue to preserve damage.
    if ($newLevel > $oldLevel) {
      $character->restoreVitals();
    }

    return self::grantAutomaticRoleSkills($character, $oldLevel, $newLevel, $experience);
  }

  /**
   * Awards every travelling member, including members outside the active battle formation.
   *
   * @return ExperienceAwardResult[]
   */
  public static function awardParty(Party $party, int $experience): array
  {
    return array_map(
      static fn(Character $character): ExperienceAwardResult => self::award($character, $experience),
      $party->members->toArray(),
    );
  }

  /**
   * Reconciles only automatic role-level grants at or below the restored level.
   * This is safe to repeat and never touches optional book learnables.
   */
  public static function reconcileAutomaticRoleSkills(Character $character): ExperienceAwardResult
  {
    return self::grantAutomaticRoleSkills($character, 0, $character->level, 0);
  }

  private static function grantAutomaticRoleSkills(
    Character $character,
    int $minimumExclusiveLevel,
    int $maximumInclusiveLevel,
    int $experience,
  ): ExperienceAwardResult
  {
    $abilities = [];
    $magic = [];

    foreach ($character->role->skillsToLearn as $grant) {
      if ($grant->level <= $minimumExclusiveLevel || $grant->level > $maximumInclusiveLevel) {
        continue;
      }

      if (! $character->learnSkill($grant->skill)) {
        continue;
      }

      if ($grant->skill instanceof MagicSkill) {
        $magic[] = $grant->skill->name;
      } else {
        $abilities[] = $grant->skill->name;
      }
    }

    return new ExperienceAwardResult(
      $character,
      $experience,
      max(1, $minimumExclusiveLevel),
      $maximumInclusiveLevel,
      $abilities,
      $magic,
    );
  }
}
