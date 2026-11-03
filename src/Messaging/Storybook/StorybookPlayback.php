<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Storybook;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use InvalidArgumentException;

/** One reader position shared by graphical and Terminal projections. */
final class StorybookPlayback
{
    private int $pageIndex = 0;
    private int $continuationIndex = 0;
    /** @var array<int, non-empty-list<string>> */
    private array $continuations = [];

    public private(set) bool $isClosed = false;

    public StorybookPage $page {
        get => $this->book->pages[$this->pageIndex];
    }

    public int $pageNumber {
        get => $this->pageIndex + 1;
    }

    public int $continuationNumber {
        get => $this->continuationIndex + 1;
    }

    public int $continuationCount {
        get => count($this->getPageContinuations($this->pageIndex));
    }

    /** Display wrapping never replaces the original text held by page. */
    public string $readingText {
        get => $this->getPageContinuations($this->pageIndex)[$this->continuationIndex];
    }

    public bool $hasPrevious {
        get => !$this->isClosed && ($this->pageIndex > 0 || $this->continuationIndex > 0);
    }

    public bool $hasNext {
        get => !$this->isClosed && ($this->continuationNumber < $this->continuationCount
            || $this->pageNumber < $this->book->pageCount);
    }

    /** The host negotiates one reading capacity for both projections. */
    public function __construct(
        public readonly StorybookDefinition $book,
        public private(set) int $columns,
        public private(set) int $rows,
    ) {
        if ($columns < 1 || $rows < 1) {
            throw new InvalidArgumentException('Storybook reading capacity requires positive columns and rows.');
        }
        $this->getPageContinuations(0);
    }

    /** Continuations retain the same authored page and picture until its text ends. */
    public function advance(): void
    {
        if ($this->isClosed) {
            return;
        }
        if ($this->continuationNumber < $this->continuationCount) {
            $this->continuationIndex++;
        } elseif ($this->pageNumber < $this->book->pageCount) {
            // Qualify the next page before changing the owner position.
            $this->getPageContinuations($this->pageIndex + 1);
            $this->pageIndex++;
            $this->continuationIndex = 0;
        } else {
            $this->close();
        }
    }

    public function goBack(): void
    {
        if (!$this->hasPrevious) {
            return;
        }
        if ($this->continuationIndex > 0) {
            $this->continuationIndex--;
        } else {
            $parts = $this->getPageContinuations($this->pageIndex - 1);
            $this->pageIndex--;
            $this->continuationIndex = count($parts) - 1;
        }
    }

    /** Reflow keeps the first visible non-whitespace character inside the new continuation. */
    public function reflow(int $columns, int $rows): void
    {
        if ($columns < 1 || $rows < 1) {
            throw new InvalidArgumentException('Storybook reading capacity requires positive columns and rows.');
        }
        if ($this->isClosed || ($columns === $this->columns && $rows === $this->rows)) {
            return;
        }
        $offset = 0;
        foreach (array_slice($this->getPageContinuations($this->pageIndex), 0, $this->continuationIndex) as $part) {
            $offset += self::countReadingCharacters($part);
        }
        // Qualify new geometry and text before changing any owner state.
        $parts = self::buildContinuations($this->page, $columns, $rows);
        $start = 0;
        $index = 0;
        foreach ($parts as $index => $part) {
            $end = $start + self::countReadingCharacters($part);
            if ($offset < $end || $index === count($parts) - 1) {
                break;
            }
            $start = $end;
        }
        $this->columns = $columns;
        $this->rows = $rows;
        $this->continuations = [$this->pageIndex => $parts];
        $this->continuationIndex = $index;
    }

    private static function countReadingCharacters(string $text): int
    {
        return mb_strlen(preg_replace('/\\s+/u', '', TerminalText::stripAnsi($text)));
    }

    /** Closing has no completion flag, reward or persistent progress outcome. */
    public function close(): void
    {
        $this->isClosed = true;
    }

    /** @return non-empty-list<string> */
    private function getPageContinuations(int $index): array
    {
        return $this->continuations[$index] ??= self::buildContinuations($this->book->pages[$index], $this->columns, $this->rows);
    }

    /** @return non-empty-list<string> */
    private static function buildContinuations(StorybookPage $page, int $columns, int $rows): array
    {
        $lines = DialoguePaginationBuilder::wrapMessageIntoLines($page->text, $columns);
        foreach ($lines as $line) {
            if (TerminalText::displayWidth($line) > $columns
                || mb_strlen(TerminalText::stripAnsi($line)) > $columns) {
                throw new InvalidArgumentException('Storybook reading area cannot fit a narration symbol.');
            }
        }
        return array_map(static fn(array $part): string => implode("\n", $part), array_chunk($lines, $rows));
    }
}
