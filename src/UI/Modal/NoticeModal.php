<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;

/** Shares ordinary modal input, dismissal, restoration and terminal rendering. */
final class NoticeModal extends PagedAlertModal
{
  public function __construct(Game $game, public readonly NoticeContent $notice)
  {
    parent::__construct($game, $notice->message, $notice->title);
  }

  public function getModalPresentation(): ?ModalPresentation
  {
    return new ModalPresentation($this->title, $this->message, $this->buttons, $this->activeIndex,
      singleConfirmation: true, notice: $this->notice);
  }

  public function show(): void
  {
    $wasShowing = $this->isShowing;
    parent::show();
    if (!$wasShowing && $this->isShowing && !$this->game->hasStopped() && $this->notice->sound !== null) {
      $this->game->audioManager->playSystemSound($this->notice->sound);
    }
  }
}
