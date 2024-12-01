<?php

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Override;

/**
 * The PromptModal class.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class PromptModal extends Modal
{

  /**
   * Constructs a new PromptModal instance.
   *
   * @param Game $game The game instance.
   * @param string $message The message to display.
   * @param string $title The title of the modal.
   * @param string $default The default value of the prompt.
   * @param int $width The width of the modal.
   */
  public function __construct(
    Game $game,
    string $message,
    string $title,
    string $default ='',
    int $width = DEFAULT_DIALOG_WIDTH
  )
  {
    parent::__construct($game, $message, $title, new Rect(0, 0, $width, DEFAULT_DIALOG_HEIGHT));
    $this->value = $default;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function submit(): void
  {
    $this->hide();
  }
}