<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use InvalidArgumentException;
use RuntimeException;

/** Shared acknowledgement pages, usable without a blocking window or Game bootstrap. */
final class NoticePageModel
{
    public private(set) int $index = 0;

    /** @param non-empty-list<string> $pages */
    private function __construct(
        public readonly string $title,
        public readonly string $sourceMessage,
        public readonly array $pages,
        public readonly bool $graphical,
        private readonly ?NoticeContent $notice,
    ) {}

    public string $message { get => $this->pages[$this->index]; }
    public bool $hasNextPage { get => isset($this->pages[$this->index + 1]); }
    public string $progress {
        get => count($this->pages) > 1 ? sprintf('%d / %d', $this->index + 1, count($this->pages)) : '';
    }

    /** Reserve the actual rows occupied by the host's supplemental control hints. */
    public static function paginateTerminal(ModalPresentation $source, int $columns, int $maximumRows, int $reservedRows = 0): self
    {
        self::assertAcknowledgement($source);
        if ($columns < 1 || $maximumRows < 1 || $reservedRows < 0) {
            throw new InvalidArgumentException('Notice pages require positive columns and rows with a nonnegative hint reservation.');
        }
        $rows = $maximumRows - $reservedRows;
        if ($rows < 1) { throw new RuntimeException('Notice controls leave no finite reading area.'); }
        $title = $source->title;
        $message = $source->message;
        $sourceMessage = $source->sourceMessage ?? $message;
        if (count(TerminalText::wrapParagraphsToWidth($title, $columns)) > 1) {
            $message = $title . "\n\n" . $message;
            $sourceMessage = $title . "\n\n" . $sourceMessage;
            $title = 'Information';
        }
        $pages = array_map(static fn(array $page): string => implode("\n", $page),
            array_chunk(TerminalText::wrapParagraphsToWidth($message, $columns), $rows)) ?: [''];
        return new self($title, $sourceMessage, $pages, false, $source->notice);
    }

    /** The graphical theme owns the reading area; the same layout feeds windows and captures. */
    public static function paginateCanvas(ModalPresentation $source, PresentationCanvas $canvas, MenuPresentationCatalog $theme): self
    {
        self::assertAcknowledgement($source);
        $reading = MenuModalPresentation::measureReadingArea($canvas, $source, $theme);
        $pages = MenuTextWrap::paginate($source->message, $reading['messageColumns'], $reading['maximumRows']);
        return new self($source->title, $source->sourceMessage ?? $source->message, $pages ?: [''], true, $source->notice);
    }

    /** False leaves the last page selected; the host owns final dismissal. */
    public function turnPage(): bool
    {
        if (!$this->hasNextPage) { return false; }
        $this->index++;
        return true;
    }

    public function getModalPresentation(): ModalPresentation
    {
        return new ModalPresentation($this->title, $this->message, [$this->hasNextPage ? 'Next' : 'OK'], 0,
            singleConfirmation: true, notice: $this->notice, sourceMessage: $this->sourceMessage);
    }

    private static function assertAcknowledgement(ModalPresentation $source): void
    {
        if (!$source->singleConfirmation) {
            throw new InvalidArgumentException('Notice paging requires a single acknowledgement.');
        }
    }
}
