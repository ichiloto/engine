<?php

namespace Ichiloto\Engine\Battle\Entry;

use InvalidArgumentException;

/** A rule-local content error; no effects or writes from this rule may execute. */
final class InvalidBattleEntryActorReference extends InvalidArgumentException
{
}
