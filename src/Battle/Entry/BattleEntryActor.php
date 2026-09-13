<?php

namespace Ichiloto\Engine\Battle\Entry;

use Ichiloto\Engine\Entities\Character;

/** One actor captured in the immutable battle-entry roster. */
final readonly class BattleEntryActor
{
  public function __construct(
    public string $actorId,
    public Character $character,
  )
  {
  }
}
