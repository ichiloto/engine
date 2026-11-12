<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Messaging\Storybook\StorybookPage;
use Ichiloto\Engine\Messaging\Storybook\StorybookPlayback;

/** Immutable projection of the modal's one reader position. */
final readonly class StorybookPresentation
{
    public StorybookPage $page;
    public string $narrator;
    public string $readingText;
    public int $pageNumber;
    public int $pageCount;
    public int $continuationNumber;
    public int $continuationCount;
    public StorybookReadingCapacity $capacity;

    public function __construct(StorybookPlayback $reader)
    {
        $this->page = $reader->page;
        $this->narrator = $reader->book->narrator;
        $this->readingText = $reader->readingText;
        $this->pageNumber = $reader->pageNumber;
        $this->pageCount = $reader->book->pageCount;
        $this->continuationNumber = $reader->continuationNumber;
        $this->continuationCount = $reader->continuationCount;
        $this->capacity = new StorybookReadingCapacity($reader->columns, $reader->rows);
    }
}
