<?php

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Override;

/**
 * The ConfirmModal class.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class ConfirmModal extends Modal
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
    protected string $confirmButton = 'OK',
    protected string $cancelButton = 'Cancel'
  )
  {
    parent::__construct(
      $game,
      $message,
      $title,
      new Rect(0, 0, $width, DEFAULT_DIALOG_HEIGHT),
      [$this->confirmButton, $this->cancelButton]
    );
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function submit(): void
  {
    parent::submit();
    $this->value =$this->activeIndex === 0;
  }
}