<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Entities\Stats\StatKey;

/** The first typed battle-entry effect: a temporary actor stat stage. */
final readonly class BattleEntryStatStageEffect
{
  public function __construct(
    public string $actorId,
    public StatKey $stat,
    public int $delta,
  )
  {
  }
}
