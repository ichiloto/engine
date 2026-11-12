<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\Messaging\Storybook\StorybookPlayback;
use Ichiloto\Engine\Messaging\Storybook\StorybookOutcome;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\UI\Interfaces\StorybookReadingCapacityProviderInterface;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use RuntimeException;

/** Full reading frame over the existing modal lifecycle, with one shared page owner. */
class StorybookModal extends Modal
{
    private StorybookPlayback $reader;
    /** @var list<string> */
    private array $headerLines;
    /** @var non-empty-list<StorybookControl> */
    private array $controls;
    private int $screenWidth;
    private int $screenHeight;
    private string $frameTitle;
    private string $controlText;
    /** @var list<string> */
    private array $hintLines;

    private ?StorybookReadingCapacity $measuredProjectionCapacity;

    /** A live host measures the current surface; a fixed capacity remains supported for isolated readers. */
    public function __construct(Game $game, StorybookDefinition $book,
        private readonly StorybookReadingCapacity|StorybookReadingCapacityProviderInterface|null $projectionCapacity = null)
    {
        $this->measuredProjectionCapacity = $this->measureProjectionCapacity($book);
        $layout = $this->measureReadingLayout($book, $this->measuredProjectionCapacity);
        $this->headerLines = $layout['header'];
        $this->hintLines = $layout['hints'];
        $this->frameTitle = $layout['frameTitle'];
        $this->controlText = $layout['controlText'];
        $this->reader = new StorybookPlayback($book, $layout['columns'], $layout['rows']);
        $this->screenWidth = get_screen_width();
        $this->screenHeight = get_screen_height();
        parent::__construct($game, $this->reader->readingText, $book->title, new Rect(0, 0, $layout['width'], 2));
        $this->updateReaderContent();
    }

    /** @return array{width:int,columns:int,rows:int,header:list<string>,hints:list<string>,frameTitle:string,controlText:string} */
    private function measureReadingLayout(StorybookDefinition $book, ?StorybookReadingCapacity $capacity): array
    {
        $width = get_screen_width() - 4;
        $terminalColumns = $width - 4; // Borders and one cell of horizontal padding on each side.
        $controlsWidth = max(TerminalText::displayWidth(StorybookControl::PREVIOUS->value . ' ' . StorybookControl::NEXT->value),
            TerminalText::displayWidth(StorybookControl::PREVIOUS->value . ' ' . StorybookControl::CLOSE->value));
        if ($terminalColumns < $controlsWidth) {
            throw new RuntimeException('Storybook reader controls require a wider Terminal viewport.');
        }
        $frameTitle = '◇ ' . $book->title . ' ◇';
        $titleLines = [];
        if (TerminalText::displayWidth($frameTitle) > $width - 3) {
            $frameTitle = '◇';
            $titleLines = DialoguePaginationBuilder::wrapMessageIntoLines($book->title, $terminalColumns);
        }
        $header = [...$titleLines, ...DialoguePaginationBuilder::wrapMessageIntoLines($book->narrator, $terminalColumns), ''];
        $controlText = TerminalControlText::formatActions([['confirm', 'Confirm'], ['cancel', 'Close']]);
        $hints = DialoguePaginationBuilder::wrapMessageIntoLines($controlText, $terminalColumns);
        $terminalRows = get_screen_height() - 2 - 3 - count($header) - count($hints);
        if ($terminalRows < 1) {
            throw new RuntimeException('Storybook header and controls leave no finite reading area.');
        }
        $center = static fn(string $line): string => TerminalText::padCenter($line, $terminalColumns);
        return ['width' => $width,
            'columns' => min($terminalColumns, $capacity?->columns ?? $terminalColumns),
            'rows' => min($terminalRows, $capacity?->rows ?? $terminalRows),
            'header' => array_map($center, $header), 'hints' => array_map($center, $hints),
            'frameTitle' => $frameTitle, 'controlText' => $controlText];
    }

    public function update(): void
    {
        $this->refreshReadingLayout();
        parent::update();
    }

    private function measureProjectionCapacity(StorybookDefinition $book): ?StorybookReadingCapacity
    {
        return $this->projectionCapacity instanceof StorybookReadingCapacityProviderInterface
            ? $this->projectionCapacity->getStorybookReadingCapacity($book) : $this->projectionCapacity;
    }

    private function refreshReadingLayout(): void
    {
        // Surface metrics and graphical control glyphs can change independently of Terminal dimensions and labels.
        // Measuring is cheap; reflow and frame reconstruction only run when an actual reading constraint changes.
        $capacity = $this->measureProjectionCapacity($this->reader->book);
        if ($this->measuredProjectionCapacity == $capacity && $this->screenWidth === get_screen_width() && $this->screenHeight === get_screen_height()
            && $this->controlText === TerminalControlText::formatActions([['confirm', 'Confirm'], ['cancel', 'Close']])) {
            return;
        }
        $layout = $this->measureReadingLayout($this->reader->book, $capacity);
        $this->reader->reflow($layout['columns'], $layout['rows']);
        $this->measuredProjectionCapacity = $capacity;
        $this->erase();
        $this->headerLines = $layout['header'];
        $this->hintLines = $layout['hints'];
        $this->frameTitle = $layout['frameTitle'];
        $this->controlText = $layout['controlText'];
        $this->rect->setWidth($layout['width']);
        $this->screenWidth = get_screen_width();
        $this->screenHeight = get_screen_height();
        $this->updateReaderContent();
    }

    public function getModalPresentation(): ?ModalPresentation
    {
        return new ModalPresentation($this->title, $this->reader->readingText, $this->buttons, $this->activeIndex,
            singleConfirmation: count($this->buttons) === 1, sourceMessage: $this->reader->page->text,
            storybook: new StorybookPresentation($this->reader));
    }

    public function show(): void
    {
        $this->refreshReadingLayout();
        InputManager::consumeCurrentInput();
        parent::show();
    }

    public function render(): void
    {
        $this->window->setHelp($this->help ?? '');
        $this->window->setContent([...$this->content,
            TerminalText::padCenter($this->renderedButtonLine(), $this->window->getContentWidth()), ...$this->hintLines]);
        PresentationLayerPolicy::ui($this, $this->window->render(...));
    }

    protected function submit(): void
    {
        if ($this->controls[$this->activeIndex] === StorybookControl::PREVIOUS) {
            $this->reader->goBack();
        } else {
            $this->reader->advance();
        }
        if ($this->reader->isClosed) {
            $this->hide();
            return;
        }
        $this->updateReaderContent();
    }

    public function getReadingOutcome(): ?StorybookOutcome
    {
        return $this->reader->outcome;
    }

    public function hide(): void
    {
        $this->reader->close();
        parent::hide();
    }

    /** Read-only narration uses the existing quiet dialogue interaction. */
    protected function playInteractionSound(SystemSound $sound): void {}

    protected function fitContentToWidth(): void
    {
        $columns = $this->rect->getWidth() - 4;
        $indent = str_repeat(' ', intdiv($columns - $this->reader->columns, 2));
        $lines = array_map(static fn(string $line): string => $indent . $line, explode("\n", $this->reader->readingText));
        $this->content = [...$this->headerLines, ...$lines,
            ...array_fill(0, $this->reader->rows - count($lines), '')];
        $this->contentHeight = count($this->content);
        $this->rect->setHeight($this->contentHeight + 3 + count($this->hintLines));
        $this->rebuildWindow();
    }

    protected function rebuildWindow(): void
    {
        $this->window = new Window($this->frameTitle, $this->help ?? '', $this->rect->position,
            $this->rect->getWidth(), $this->rect->getHeight(), $this->borderPack,
            WindowAlignment::topLeft(), new WindowPadding(rightPadding: 1, leftPadding: 1),
            heightPolicy: WindowHeightPolicy::FIXED);
    }

    private function updateReaderContent(): void
    {
        $this->message = $this->reader->readingText;
        $this->controls = [...($this->reader->hasPrevious ? [StorybookControl::PREVIOUS] : []),
            $this->reader->hasNext ? StorybookControl::NEXT : StorybookControl::CLOSE];
        $this->buttons = array_map(static fn(StorybookControl $control): string => $control->value, $this->controls);
        $this->activeIndex = count($this->buttons) - 1;
        $this->help = sprintf('%d/%d', $this->reader->pageNumber, $this->reader->book->pageCount)
            . ($this->reader->continuationCount > 1
                ? sprintf(' (%d/%d)', $this->reader->continuationNumber, $this->reader->continuationCount) : '');
        if (TerminalText::displayWidth(implode(' ', $this->buttons)) > $this->rect->getWidth() - 4) {
            throw new RuntimeException('Storybook reader controls require a wider Terminal viewport.');
        }
        $this->fitContentToWidth();
        $this->positionForOpen();
    }
}
