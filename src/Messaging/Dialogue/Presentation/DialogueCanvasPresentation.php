<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use RuntimeException;

/** Pure composition: the modal still owns typing, pages, input and voice. */
final class DialogueCanvasPresentation
{
    public static function compose(DialogueSnapshot $line, DialoguePresentationCatalog $catalogue,
        int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT,
        bool $supportsImageTone = true): PresentationCanvas
    {
        $theme = $catalogue->theme ?? throw new RuntimeException('Dialogue has no graphical theme.');
        $view = new MenuCanvas($theme, $width, $height);
        $m = $theme->metrics;
        $compact = $width < 1000;
        $skit = $line->context->skitId !== null;
        $margin = $compact ? 20 : ($skit ? 72 : 40);
        $padding = $m->panelPadding;
        $speakerId = $catalogue->resolveSpeakerId($line->context->actorId, $line->speaker);
        $portrait = !$skit && $line->speaker !== '' ? $catalogue->getArtwork($speakerId, $line->context->emotion, 'portrait') : null;
        $gutter = $portrait === null ? 0 : ($compact ? 132 : 200);
        $textWidth = $width - 2 * ($margin + $padding) - $gutter;
        $columns = (int)floor($textWidth / $m->cellWidth);
        $textHeight = max($compact ? 2 : 3, count(MenuCanvas::wrap($line->page, $columns))) * $m->cellHeight;
        $nameLines = $line->speaker === '' ? [] : MenuCanvas::wrap($line->speaker,
            (int)floor(($textWidth - 32) / $m->cellWidth));
        $nameHeight = count($nameLines) * $m->cellHeight + ($nameLines === [] ? 0 : 24);
        $helpHeight = $line->help === '' ? 0 : count(MenuCanvas::wrap($line->help, $columns)) * $m->cellHeight + 8;
        $footerHeight = $m->rowHeight + 12;
        $bodyHeight = $textHeight + $helpHeight + 2 * $padding + $footerHeight;
        $bottom = $skit ? 24 : 28;
        $top = match ($skit ? WindowPosition::BOTTOM : $line->position) {
            WindowPosition::TOP => 32 + max(0, $nameHeight - 12),
            WindowPosition::MIDDLE => ($height - $bodyHeight) / 2,
            WindowPosition::BOTTOM => $height - $bottom - $bodyHeight,
        };
        if ($top < max(0, $nameHeight - 12) || $top + $bodyHeight > $height) {
            throw new RuntimeException('Dialogue content exceeds its readable viewport; terminal presentation retained.');
        }
        $box = new CanvasRectangle($margin, $top, $width - 2 * $margin, $bodyHeight);
        $images = $skit ? SkitCanvasPresentation::renderStage($view, $line, $catalogue, $box, $supportsImageTone) : [];
        $view->frame('dialogue-body', $box, 'dialogue');
        $x = $margin + $padding + $gutter;
        if ($nameLines !== []) {
            $nameWidth = min($textWidth, max(128, max(array_map(mb_strlen(...), $nameLines)) * $m->cellWidth + 32));
            $plate = new CanvasRectangle($x, $top - $nameHeight + 12, $nameWidth, $nameHeight);
            $view->frame('dialogue-nameplate', $plate, 'nameplate', 21);
            $view->prose('dialogue-speaker', $line->speaker,
                new CanvasRectangle($x + 16, $plate->y + 12, $nameWidth - 32, $nameHeight - 24), 'focus', HorizontalAlignment::CENTER);
        }
        if ($portrait !== null) {
            $dock = new CanvasRectangle($margin + $padding, max(0, $top - 36), $compact ? 116 : 180,
                min($compact ? 154 : 222, $bodyHeight + 8));
            $view->surface('dialogue-portrait-backing', new CanvasRectangle($dock->x + 8, $dock->y + 8,
                $dock->width - 16, $dock->height - 16), 'panel', 20);
            $view->frame('dialogue-portrait-frame', $dock, 'portrait', 21);
            array_push($images, ...MenuIconRegistry::containAsset($theme->assetRoot, 'dialogue-portrait', $portrait,
                new CanvasRectangle($dock->x + 8, $dock->y + 8, $dock->width - 16, $dock->height - 16), 22, $dock));
        }
        $prose = self::renderText($line, new CanvasRectangle($x, $top + $padding, $textWidth, $textHeight),
            $m->cellWidth, $m->cellHeight, $theme->colors['text']);
        $footerY = $top + $bodyHeight - $padding - $m->rowHeight;
        if ($line->help !== '') {
            $view->prose('dialogue-authored-help', $line->help,
                new CanvasRectangle($x, $footerY - $helpHeight - 8, $textWidth, $helpHeight - 8), 'disabled');
        }
        $view->surface('dialogue-footer-rule', new CanvasRectangle($x, $footerY - 8, $textWidth, 1), 'edge', 20);
        if ($line->pageCount > 1) {
            $view->renderBorderCaption('dialogue-page', ($line->pageIndex + 1) . ' / ' . $line->pageCount,
                new CanvasRectangle($x, $top + $bodyHeight - 20, $textWidth, 16));
        }
        $bindings = new InputBindings();
        $autoWidth = max(120, 9 * $m->cellWidth + 2 * $theme->rows->metrics->padding);
        $view->rows('dialogue-auto', [new MenuRow('auto', 'Auto ' . ($line->auto ? 'On' : 'Off'), kind: MenuRowKind::BUTTON,
            selected: $line->auto, showCursor: false)],
            new MenuRowLayout(new CanvasRectangle($x, $footerY, $autoWidth, $m->rowHeight),
                rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight));
        $autoHint = $bindings->controlForAction('dialogue_auto')?->label ?? 'Unbound';
        $autoHintWidth = mb_strlen($autoHint) * $m->cellWidth;
        $view->prose('dialogue-auto-hint', $autoHint,
            new CanvasRectangle($x + $autoWidth + 8, $footerY, $autoHintWidth, $m->rowHeight), 'disabled');
        $hint = $line->isPrinting ? 'Reveal' : 'Continue';
        $label = ($bindings->controlForAction('confirm')?->label ?? 'Unbound') . ' ' . $hint;
        $view->prose('dialogue-advance', $label,
            new CanvasRectangle($x + $autoWidth + $autoHintWidth + 16, $footerY,
                $textWidth - $autoWidth - $autoHintWidth - 40, $m->rowHeight),
            'text', HorizontalAlignment::RIGHT);
        if (!$line->isPrinting) {
            $view->icon('dialogue-ready', 'navigation.continue', new CanvasRectangle($width - $margin - $padding - 18,
                $footerY + ($m->rowHeight - 18) / 2, 18, 18));
        }
        $canvas = $view->finish();
        \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight::inspect([...$images, ...$canvas->images], $theme->assetRoot);
        // An ordinary dialogue has no whole-scene fill; skits own a full scene surface.
        $text = $skit ? $canvas->textLayers : array_values(array_filter($canvas->textLayers,
            static fn($layer) => $layer->id !== 'menu-background' && !str_starts_with($layer->id, 'menu-background-part-')));
        return new PresentationCanvas($width, $height, [...$images, ...$canvas->images], textLayers: [...$text, $prose]);
    }

    /** Wrap the completed page once, then reveal its prefix without moving partially typed words. */
    private static function renderText(DialogueSnapshot $line, CanvasRectangle $bounds, int $cellWidth,
        int $cellHeight, PresentationColor $color): CanvasTextLayer
    {
        $columns = (int)floor($bounds->width / $cellWidth);
        $remaining = mb_strlen(MenuTextWrap::normalizeText($line->visibleText));
        $row = 0;
        $runs = [];
        foreach (explode("\n", MenuTextWrap::normalizeText($line->page)) as $paragraph) {
            foreach (MenuCanvas::wrap($paragraph, $columns) as $wrapped) {
                $text = mb_substr($wrapped, 0, max(0, $remaining));
                if ($text !== '') { $runs[] = new PresentationTextRun($row, 0, $text, $color); }
                $remaining -= mb_strlen($wrapped);
                $row++;
            }
            $remaining--; // Consume the authored paragraph separator, not visual wrap breaks.
        }
        return new CanvasTextLayer('dialogue-text', 30, $bounds->x, $bounds->y,
            new RendererGridConfig($columns, $row, $cellWidth, $cellHeight), $runs, $bounds);
    }
}
