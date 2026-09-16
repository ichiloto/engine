<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Progression\ExperienceAwardResult;
use Ichiloto\Engine\Progression\ProgressionSnapshot;
use InvalidArgumentException;

/** Award data with no live character reference in the presentation tree. */
final readonly class BattleProgression
{
  public int $oldLevel;
  public int $newLevel;

  /** @param list<array{name: string, description: string, kind: string, cost: int}> $learnedDetails */
  public function __construct(
    public int $experienceAwarded,
    public ProgressionSnapshot $before,
    public ProgressionSnapshot $after,
    public array $learnedDetails = [],
  ) {
    $this->oldLevel = $before->level;
    $this->newLevel = $after->level;
  }

  public static function fromAward(ExperienceAwardResult $award): self
  {
    if ($award->before === null || $award->after === null) {
      throw new InvalidArgumentException('Battle progression requires snapshots from the EXP award boundary.');
    }
    return new self($award->experienceAwarded, $award->before, $award->after, $award->learnedDetails);
  }

  public function levelledUp(): bool { return $this->newLevel > $this->oldLevel; }
}
