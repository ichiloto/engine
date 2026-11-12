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
    protected NoticePageModel $pageModel;
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
        $source = $this->getModalPresentation();
        $model = null;
        try {
            if ($runtime?->grid !== null && ($theme = MenuPresentationCatalog::load($runtime->getAssetRoot())) !== null) {
                $canvas = new PresentationCanvas(min(PresentationCanvas::DEFAULT_WIDTH, $runtime->grid->columns * $runtime->grid->cellWidth),
                    min(PresentationCanvas::DEFAULT_HEIGHT, $runtime->grid->rows * $runtime->grid->cellHeight));
                $model = NoticePageModel::paginateCanvas($source, $canvas, $theme);
            }
        } catch (Throwable $error) {
            Debug::warn('Alert layout retained terminal pages: ' . $error->getMessage());
        }
        $this->pageModel = $model ?? NoticePageModel::paginateTerminal($source, $columns, $rows,
            count($this->getSupplementalContent($columns)));
        $this->pages = $this->pageModel->pages;
        $this->graphicalPages = $this->pageModel->graphical;
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
        if (!$this->pageModel->hasNextPage) { parent::submit(); return; }
        $this->erase();
        $this->pageModel->turnPage();
        $this->updatePage();
        $this->fitContentToWidth();
        $this->positionForOpen();
    }

    private function updatePage(): void
    {
        $view = $this->pageModel->getModalPresentation();
        $this->title = $view->title;
        $this->sourceMessage = $this->pageModel->sourceMessage;
        $this->message = $view->message;
        $this->buttons = $view->choices;
        $this->help = $this->pageModel->progress;
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
