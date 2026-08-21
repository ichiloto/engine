<?php

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Entities\Character;

/**
 * The complete, presentation-neutral result of one character EXP award.
 */
final readonly class ExperienceAwardResult
{
  /**
   * @param string[] $learnedAbilities
   * @param string[] $learnedMagic
   */
  public function __construct(
    public Character $character,
    public int $experienceAwarded,
    public int $oldLevel,
    public int $newLevel,
    public array $learnedAbilities = [],
    public array $learnedMagic = [],
  )
  {
  }

  public function levelledUp(): bool
  {
    return $this->newLevel > $this->oldLevel;
  }

  /** @return string[] */
  public function learnedSkills(): array
  {
    return [...$this->learnedAbilities, ...$this->learnedMagic];
  }
}
