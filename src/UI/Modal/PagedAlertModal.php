<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Acknowledged information retains every line, with bounded pages instead of a growing toast. */
class PagedAlertModal extends AlertModal
{
    /** @var non-empty-list<string> */
    protected array $pages;
    protected int $pageIndex = 0;
    protected string $sourceMessage;
    private bool $graphicalPages = false;

    public function __construct(Game $game, string $message, string $title)
    {
        $width = min(DEFAULT_DIALOG_WIDTH, max(4, get_screen_width()));
        $columns = max(1, $width - 4);
        $rows = min(6, max(1, get_screen_height() - 4));
        $this->sourceMessage = $message;
        parent::__construct($game, $message, $title, $width);
        $runtime = $game->getRendererRuntime();
        try {
            if ($runtime?->grid !== null && ($theme = MenuPresentationCatalog::load($runtime->getAssetRoot())) !== null) {
                $canvas = new PresentationCanvas(min(PresentationCanvas::DEFAULT_WIDTH, $runtime->grid->columns * $runtime->grid->cellWidth),
                    min(PresentationCanvas::DEFAULT_HEIGHT, $runtime->grid->rows * $runtime->grid->cellHeight));
                $reading = MenuModalPresentation::measureReadingArea($canvas, $this->getModalPresentation(), $theme);
                $this->pages = MenuTextWrap::paginate($message, $reading['messageColumns'], $reading['maximumRows']);
                $this->graphicalPages = true;
            }
        } catch (Throwable $error) {
            Debug::warn('Alert layout retained terminal pages: ' . $error->getMessage());
        }
        if (!$this->graphicalPages) {
            $rows -= count($this->getSupplementalContent($columns));
            if ($rows < 1) { throw new \RuntimeException('Notice controls leave no finite reading area.'); }
            if (count(TerminalText::wrapParagraphsToWidth($title, $columns)) > 1) {
                $message = $title . "\n\n" . $message;
                $this->sourceMessage = $message;
                $this->title = 'Information';
            }
            $this->pages = array_map(static fn(array $page): string => implode("\n", $page),
                array_chunk(TerminalText::wrapParagraphsToWidth($message, $columns), $rows)) ?: [''];
        }
        $this->updatePage();
    }

    public function getModalPresentation(): ?ModalPresentation
    {
        return new ModalPresentation($this->title, $this->message, $this->buttons, $this->activeIndex,
            singleConfirmation: true, sourceMessage: $this->sourceMessage);
    }

    public function show(): void
    {
        InputManager::consumeCurrentInput();
        parent::show();
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

    /** @return list<string> Additional reading rows kept on each page by a specialised notice. */
    protected function getSupplementalContent(int $columns): array
    {
        return [];
    }

    protected function fitContentToWidth(): void
    {
        $this->content = [...($this->graphicalPages
            ? TerminalText::wrapParagraphsToWidth($this->message, max(1, $this->rect->getWidth() - 4))
            : explode("\n", $this->message)),
            ...$this->getSupplementalContent(max(1, $this->rect->getWidth() - 4))];
        $this->contentHeight = count($this->content);
        $this->rect->setHeight($this->contentHeight + 3);
        $this->rebuildWindow();
    }
}
