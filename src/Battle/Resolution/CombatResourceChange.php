<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Resolution;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Actual resource changes from effects, excluding caller-owned command costs. */
final class CombatResourceChange
{
  public function __construct(
    public readonly int $hpLost = 0,
    public readonly int $hpRestored = 0,
    public readonly int $mpLost = 0,
    public readonly int $mpRestored = 0,
  ) {}

  public static function measure(CharacterInterface $battler, int $previousHp, int $previousMp): self
  {
    return new self(
      max(0, $previousHp - $battler->stats->currentHp),
      max(0, $battler->stats->currentHp - $previousHp),
      max(0, $previousMp - $battler->stats->currentMp),
      max(0, $battler->stats->currentMp - $previousMp),
    );
  }

  public function accumulate(self $change): self
  {
    return new self($this->hpLost + $change->hpLost, $this->hpRestored + $change->hpRestored,
      $this->mpLost + $change->mpLost, $this->mpRestored + $change->mpRestored);
  }

  public bool $hasChanges {
    get { return $this->hpLost > 0 || $this->hpRestored > 0 || $this->mpLost > 0 || $this->mpRestored > 0; }
  }
}
