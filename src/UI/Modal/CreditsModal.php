<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\UI\Presentation\CreditsContent;

/** Centered alert pages for terminal and reduced-motion credit reading. */
class CreditsModal extends AlertModal
{
  /** @var non-empty-list<string> */
  private array $pages;
  private int $pageIndex = 0;

  public function __construct(Game $game, CreditsContent $content)
  {
    $width = min(DEFAULT_DIALOG_WIDTH, max(4, get_screen_width()));
    // The same pages also fit the graphical alert when reduced motion is enabled.
    $this->pages = $content->getPages($width - 2, min(12, max(1, get_screen_height() - 3)));
    parent::__construct($game, $this->pages[0], get_message('title.credits', 'Credits'), $width);
    $this->updatePage();
  }

  protected function submit(): void
  {
    if (!isset($this->pages[$this->pageIndex + 1])) { parent::submit(); return; }
    $this->erase();
    $this->pageIndex++;
    $this->updatePage();
    $this->fitContentToWidth();
    $this->positionForOpen();
  }

  private function updatePage(): void
  {
    $this->message = $this->pages[$this->pageIndex];
    $this->buttons = [isset($this->pages[$this->pageIndex + 1]) ? 'Next' : 'OK'];
    $this->help = count($this->pages) > 1 ? sprintf('%d / %d', $this->pageIndex + 1, count($this->pages)) : '';
  }

  protected function fitContentToWidth(): void
  {
    // CreditsContent already wraps in terminal display cells, including wide glyphs.
    $this->content = explode("\n", $this->message);
    $this->contentHeight = count($this->content);
    $this->rect->setHeight($this->contentHeight + 3);
    $this->rebuildWindow();
  }
}
