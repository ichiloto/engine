<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;

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
      singleConfirmation: true, notice: $this->notice, sourceMessage: $this->sourceMessage);
  }

  /** @return list<string> The terminal renders the same semantic hints as readable bound controls. */
  protected function getSupplementalContent(int $columns): array
  {
    $text = TerminalControlText::formatHints($this->notice->getActionHints());
    return $text === '' ? [] : ['', ...TerminalText::wrapParagraphsToWidth($text, $columns)];
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
