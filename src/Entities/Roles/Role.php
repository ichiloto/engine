<?php

namespace Ichiloto\Engine\Entities\Roles;

use Ichiloto\Engine\Entities\Character;

/**
 * Class Role. Represents a character role. A role is a set of attributes that a character can have. It defines the character's stats, skills, traits, and other properties.
 *
 * @package Ichiloto\Engine\Entities\Roles
 */
readonly class Role
{
  public ParameterCurveGenerator $totalHpCurveGenerator;
  public ParameterCurveGenerator $totalMpCurveGenerator;
  public ParameterCurveGenerator $totalAttackCurveGenerator;
  public ParameterCurveGenerator $totalDefenceCurveGenerator;
  public ParameterCurveGenerator $totalMagicAttackCurveGenerator;
  public ParameterCurveGenerator $totalMagicDefenceCurveGenerator;
  public ParameterCurveGenerator $totalSpeedCurveGenerator;
  public ParameterCurveGenerator $totalGraceCurveGenerator;
  public ParameterCurveGenerator $totalEvasionCurveGenerator;

  /**
   * @param Character $character The character.
   * @param string $name The name of the role.
   * @param ExperienceCurveGenerator $experienceCurveGenerator The experience curve.
   * @param SkillToLearn[] $skillsToLearn The skills to learn.
   * @param object[] $traits The traits.
   * @param string $note The note.
   * @param ParameterCurveGenerator|null $totalHpCurveGenerator
   * @param ParameterCurveGenerator|null $totalMpCurveGenerator
   * @param ParameterCurveGenerator|null $totalAttackCurveGenerator
   * @param ParameterCurveGenerator|null $totalDefenceCurveGenerator
   * @param ParameterCurveGenerator|null $totalMagicAttackCurveGenerator
   * @param ParameterCurveGenerator|null $totalMagicDefenceCurveGenerator
   * @param ParameterCurveGenerator|null $totalSpeedCurveGenerator
   * @param ParameterCurveGenerator|null $totalGraceCurveGenerator
   * @param ParameterCurveGenerator|null $totalEvasionCurveGenerator
   */
  public function __construct(
    protected Character             $character,
    public string                   $name,
    public ExperienceCurveGenerator $experienceCurveGenerator = new ExperienceCurveGenerator(),
    public array                    $skillsToLearn = [],
    public array                    $traits = [],
    public string                   $note = '',
    ?ParameterCurveGenerator        $totalHpCurveGenerator = null,
    ?ParameterCurveGenerator        $totalMpCurveGenerator = null,
    ?ParameterCurveGenerator        $totalAttackCurveGenerator = null,
    ?ParameterCurveGenerator        $totalDefenceCurveGenerator = null,
    ?ParameterCurveGenerator        $totalMagicAttackCurveGenerator = null,
    ?ParameterCurveGenerator        $totalMagicDefenceCurveGenerator = null,
    ?ParameterCurveGenerator        $totalSpeedCurveGenerator = null,
    ?ParameterCurveGenerator        $totalGraceCurveGenerator = null,
    ?ParameterCurveGenerator        $totalEvasionCurveGenerator = null,
  )
  {
    $this->totalHpCurveGenerator = $totalHpCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalHp, 500, 40);
    $this->totalMpCurveGenerator = $totalMpCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalMp, 100, 10);
    $this->totalAttackCurveGenerator = $totalAttackCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalAttack, 50, 1);
    $this->totalDefenceCurveGenerator = $totalDefenceCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalDefence, 30, 1);
    $this->totalMagicAttackCurveGenerator = $totalMagicAttackCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalMagicAttack, 50, 1);
    $this->totalMagicDefenceCurveGenerator = $totalMagicDefenceCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalMagicDefence, 30, 1);
    $this->totalSpeedCurveGenerator = $totalSpeedCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalSpeed, 20, 1);
    $this->totalGraceCurveGenerator = $totalGraceCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalGrace, 15, 1);
    $this->totalEvasionCurveGenerator = $totalEvasionCurveGenerator ?? new ParameterCurveGenerator($this->character->level, $this->character->stats->totalEvasion, 10);
  }
}