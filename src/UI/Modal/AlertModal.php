<?php

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;

/**
 * The AlertModal class.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class AlertModal extends Modal
{
  /**
   * Constructs a new AlertModal instance.
   *
   * @param Game $game The game instance.
   * @param string $message The message to display.
   * @param string $title The title of the modal.
   * @param int $width The width of the modal.
   */
  public function __construct(
    Game $game,
    string $message,
    string $title,
    int $width = DEFAULT_DIALOG_WIDTH,
  )
  {
    parent::__construct($game, $message, $title, new Rect(0, 0, $width, DEFAULT_DIALOG_HEIGHT));
  }
}