<?php

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Progression\Mastery\MasteryProgression;
use Ichiloto\Engine\Util\Config\ConfigStore;
use InvalidArgumentException;

/**
 * The single progression path for battle, quest, script, and migration EXP,
 * and for the Mastery points (SP) the same rewards give.
 */
final class ExperienceAwarder
{
  private function __construct()
  {
  }

  public static function award(Character $character, int $experience, int $skillPoints = 0): ExperienceAwardResult
  {
    if ($experience < 0) {
      throw new InvalidArgumentException('Experience awards cannot be negative.');
    }
    if ($skillPoints < 0) {
      throw new InvalidArgumentException('Mastery point awards cannot be negative.');
    }

    $settings = ConfigStore::has(ProgressionSettings::class)
      ? ConfigStore::get(ProgressionSettings::class)
      : new ProgressionSettings();
    if (! $settings instanceof ProgressionSettings) {
      throw new InvalidArgumentException('Progression settings must use the validated project contract.');
    }

    $before = ProgressionSnapshot::capture($character);
    $oldLevel = $before->level;
    $previousTotalHp = $character->stats->totalHp;
    $previousTotalMp = $character->stats->totalMp;
    $character->addExperience($experience);
    $newLevel = $character->level;

    // Level recovery is part of progression, not generic stat recalculation.
    // Keeping it here makes battle, quest, and scripted EXP agree while
    // equipment changes and save hydration continue to preserve damage.
    if ($newLevel > $oldLevel) {
      $settings->levelUpRecovery->recover($character, $previousTotalHp, $previousTotalMp);
    }

    // Points join the total before the map is brought up to the new level, which may open free nodes.
    $character->mastery->addPoints($skillPoints);

    return self::grantAutomaticRoleSkills($character, $oldLevel, $newLevel, $experience, $before, $skillPoints);
  }

  /**
   * Awards every travelling member, including members outside the active battle formation.
   *
   * @return ExperienceAwardResult[]
   */
  public static function awardParty(Party $party, int $experience, int $skillPoints = 0): array
  {
    return array_map(
      static fn(Character $character): ExperienceAwardResult => self::award($character, $experience, $skillPoints),
      $party->members->toArray(),
    );
  }

  /**
   * Reconciles automatic role-level grants at or below the restored level, and
   * the character's Mastery Map with what they know and their level. This is
   * safe to repeat and never touches optional book learnables.
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
    ?ProgressionSnapshot $before = null,
    int $skillPoints = 0,
  ): ExperienceAwardResult
  {
    $abilities = [];
    $magic = [];
    $details = [];

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
      $details[] = ['name' => $grant->skill->name, 'description' => $grant->skill->description,
        'kind' => $grant->skill instanceof MagicSkill ? 'Magic' : 'Ability', 'cost' => $grant->skill->cost];
    }

    // The Mastery Map comes up to what the character knows and their level: free nodes that opened are learned.
    foreach (MasteryProgression::forCharacter($character)?->reconcile($character)->learned ?? [] as $skill) {
      if ($skill instanceof MagicSkill) {
        $magic[] = $skill->name;
      } else {
        $abilities[] = $skill->name;
      }
      $details[] = ['name' => $skill->name, 'description' => $skill->description,
        'kind' => $skill instanceof MagicSkill ? 'Magic' : 'Ability', 'cost' => $skill->cost];
    }

    return new ExperienceAwardResult(
      $character,
      $experience,
      max(1, $minimumExclusiveLevel),
      $maximumInclusiveLevel,
      $abilities,
      $magic,
      $before,
      ProgressionSnapshot::capture($character),
      $details,
      $skillPoints,
    );
  }
}
