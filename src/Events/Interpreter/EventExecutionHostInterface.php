<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Battle\Events\BattleEventControlInterface;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;

/**
 * Where an event interpreter's sessions run: the scope that decides their
 * commands, the world state and party they change, and the lifecycle hooks
 * the host owns. The field scene hosts map scripts; a battle hosts its
 * troop's event pages.
 *
 * @package Ichiloto\Engine\Events\Interpreter
 */
interface EventExecutionHostInterface
{
  /** Returns the scope the host's scripts run in. */
  public function getEventScope(): EventExecutionScope;

  /** Returns the world state scripts read and change. */
  public function getEventWorldState(): GameState;

  /** Returns the party scripts give items and gold to, if there is one. */
  public function getEventParty(): ?Party;

  /** Returns the battle a battle event page runs in; null for field scripts. */
  public function getBattleControl(): ?BattleEventControlInterface;

  /** Called when a session starts. */
  public function onEventSessionStarted(EventExecutionSession $session): void;

  /** Called after a session completes or fails. */
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void;
}
