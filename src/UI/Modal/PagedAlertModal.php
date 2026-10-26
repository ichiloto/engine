<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Acknowledged information retains every line, with bounded pages instead of a growing toast. */
class PagedAlertModal extends AlertModal
{
    /** @var non-empty-list<string> */
    protected array $pages;
    protected int $pageIndex = 0;

    public function __construct(Game $game, string $message, string $title)
    {
        $width = min(DEFAULT_DIALOG_WIDTH, max(4, get_screen_width()));
        $columns = max(1, $width - 4);
        $rows = min(6, max(1, get_screen_height() - 4));
        $runtime = $game->getRendererRuntime();
        try {
            if ($runtime?->grid !== null && ($theme = MenuPresentationCatalog::load($runtime->getAssetRoot())) !== null) {
                $m = $theme->metrics;
                $canvasWidth = min(PresentationCanvas::DEFAULT_WIDTH, $runtime->grid->columns * $runtime->grid->cellWidth);
                $canvasHeight = min(PresentationCanvas::DEFAULT_HEIGHT, $runtime->grid->rows * $runtime->grid->cellHeight);
                $columns = min($columns, max(1, (int)floor(($canvasWidth * 2 / 3 - 2 * $m->panelPadding) / $m->cellWidth)));
                $rows = min($rows, max(1, (int)floor(($canvasHeight - 4 * $m->panelPadding - $m->rowHeight
                    - 3 * $m->cellHeight - 4 * $m->sectionGap) / $m->cellHeight)));
            }
        } catch (Throwable $error) {
            Debug::warn('Alert layout retained terminal pages: ' . $error->getMessage());
        }
        // A long heading is reading content too; retain it on the pages, not an oversized nameplate.
        if (count(TerminalText::wrapParagraphsToWidth($title, $columns)) > 1) {
            $message = $title . "\n\n" . $message;
            $title = 'Information';
        }
        $this->pages = array_map(static fn(array $page): string => implode("\n", $page),
            array_chunk(TerminalText::wrapParagraphsToWidth($message, $columns), $rows)) ?: [''];
        parent::__construct($game, $this->pages[0], $title, $width);
        $this->updatePage();
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

    protected function fitContentToWidth(): void
    {
        $this->content = explode("\n", $this->message);
        $this->contentHeight = count($this->content);
        $this->rect->setHeight($this->contentHeight + 3);
        $this->rebuildWindow();
    }
}
