<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Ichiloto\Engine\Localization\Vocabulary;

/**
 * The project's words for Mastery points, read from its vocabulary
 * (`vocab.mastery.points`, `vocab.mastery.points_name`) with the Engine's
 * defaults in Vocabulary::DEFAULTS, so menus, battle results and quest
 * rewards all say the same and the Editor offers both terms.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryVocabulary
{
  public const string POINTS = 'mastery.points';
  public const string POINTS_NAME = 'mastery.points_name';

  private function __construct()
  {
  }

  /** The short label shown beside every amount: "SP" unless the project words it otherwise. */
  public static function getPointsLabel(): string
  {
    return Vocabulary::getLabel(self::POINTS, Vocabulary::DEFAULTS[self::POINTS]);
  }

  /** The spelled-out name, used once in help: "Skill Points" unless the project words it otherwise. */
  public static function getPointsName(): string
  {
    return Vocabulary::getLabel(self::POINTS_NAME, Vocabulary::DEFAULTS[self::POINTS_NAME]);
  }
}
