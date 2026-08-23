<?php

namespace Ichiloto\Engine\Battle\Entry;

/** One actor-presence condition on a battle-entry rule. */
final readonly class BattleEntryActorPredicate
{
  public function __construct(
    public string $actorId,
    public BattleEntryActorPresence $presence,
  )
  {
  }
}
