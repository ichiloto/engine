<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Game;

/**
 * The scene event dialogue and choices are shown over. It restores its own
 * composition once an event overlay closes.
 *
 * @package Ichiloto\Engine\Events\Interpreter
 */
interface EventPresentationHostInterface
{
  public function getGame(): Game;

  /** Redraws the scene after an event dialogue or choice closes. */
  public function restoreAfterEventOverlay(): void;
}
