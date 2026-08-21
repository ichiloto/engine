<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Receives the terminal outcome of an event session without storing a
 * closure in the session.
 */
interface EventSessionCompletionTargetInterface
{
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void;

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void;
}
