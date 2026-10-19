<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use InvalidArgumentException;

/** Shared page content and geometry; the host still owns typing, timing and advancement. */
final readonly class DialoguePagination
{
    /** @param non-empty-list<string> $pages */
    public function __construct(
        public array $pages,
        public string $speaker,
        public string $help,
        public DialogueContext $context,
        public int $windowWidth,
        public int $windowHeight,
        public int $contentWidth,
        public int $linesPerPage,
        public WindowPosition $position,
    ) {}

    /** A null character count exposes the full page for an author-paced host. */
    public function getSnapshot(int $pageIndex, ?int $visibleCharacters = null,
        bool $isPrinting = false, bool $auto = false): DialogueSnapshot
    {
        if (!isset($this->pages[$pageIndex]) || ($visibleCharacters !== null && $visibleCharacters < 0)) {
            throw new InvalidArgumentException('Dialogue snapshot requires an existing page and a non-negative character count.');
        }
        $page = $this->pages[$pageIndex];
        return new DialogueSnapshot($this->speaker, $page,
            $visibleCharacters === null ? $page : mb_substr($page, 0, $visibleCharacters),
            $isPrinting, $pageIndex, count($this->pages), $auto, $this->position, $this->context, $this->help);
    }
}
