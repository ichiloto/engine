<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;

/** Pure pagination shared by runtime dialogue and isolated presentation hosts. */
final class DialoguePaginationBuilder
{
    public static function buildPagination(string $message, string $speaker, string $help,
        DialogueContext $context, int $screenWidth, int $screenHeight,
        ?DialoguePageLayout $layout = null, ?WindowPosition $position = null): DialoguePagination
    {
        // A graphical page uses its reading area, not the legacy Terminal modal
        // default. Keep its fallback window wide enough for that same owner page.
        $width = min($layout === null ? DEFAULT_DIALOG_WIDTH : $layout->columns + 4, max(4, $screenWidth));
        $contentWidth = max(1, $width - 4); // Borders and Window's default horizontal padding.
        $contentWidth = min($contentWidth, $layout?->columns ?? $contentWidth);
        $wrappedLines = self::wrapMessageIntoLines($message, $contentWidth);
        $screenContentLines = max(1, $screenHeight - 2);
        $linesPerPage = min($layout?->proseRows ?? DialoguePageLayout::CONTENT_ROWS, $screenContentLines);
        $pages = array_map(static fn(array $page): string => implode("\n", $page),
            array_chunk($wrappedLines, $linesPerPage));
        $height = min($screenContentLines, DialoguePageLayout::CONTENT_ROWS) + 2;
        $position ??= trim($speaker) === '' ? WindowPosition::TOP : WindowPosition::BOTTOM;

        return new DialoguePagination($pages ?: [''], $speaker, $help, $context,
            $width, $height, $contentWidth, $linesPerPage, $position);
    }

    /** @return non-empty-list<string> Lines that fit both Canvas codepoints and Terminal display cells. */
    public static function wrapMessageIntoLines(string $message, int $contentWidth): array
    {
        $width = max(1, $contentWidth);
        // Combining marks count on Canvas; wide characters consume extra Terminal cells.
        $lines = MenuTextWrap::lines($message, $width);
        return array_merge(...array_map(static fn(string $line): array =>
            TerminalText::wrapToWidth(rtrim($line, ' '), $width), $lines));
    }
}
