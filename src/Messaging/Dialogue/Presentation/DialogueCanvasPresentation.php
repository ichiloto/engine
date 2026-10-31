<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\IO\ActionHints;
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
        $layout = new DialoguePageLayout($catalogue, $line->speaker, $line->context, $line->help, $width, $line->interactive);
        $margin = $layout->margin;
        $padding = $m->panelPadding;
        $portrait = $layout->portrait;
        $gutter = $layout->gutter;
        $geometry = new DialoguePageGeometry($layout, $catalogue, $line->speaker, $line->position, $width, $height, $skit);
        $textWidth = $geometry->textWidth;
        $columns = $layout->columns;
        if (count(MenuCanvas::wrap($line->page, $columns)) > $layout->proseRows) {
            throw new RuntimeException('Dialogue page exceeds its fixed reading area; the owner must paginate it.');
        }
        $textHeight = $layout->proseRows * $m->cellHeight;
        $nameLines = $geometry->nameLines;
        $nameHeight = $geometry->nameHeight;
        $helpHeight = $layout->helpHeight;
        $bodyHeight = $layout->bodyHeight;
        $top = $geometry->body->y;
        $box = $geometry->body;
        $images = $skit ? SkitCanvasPresentation::renderStage($view, $line, $catalogue, $box, $supportsImageTone) : [];
        $view->frame('dialogue-body', $box, 'dialogue');
        $view->protect($box);
        $x = $geometry->textX;
        if ($nameLines !== []) {
            $plate = $geometry->nameplate;
            $nameWidth = $plate->width;
            $view->frame('dialogue-nameplate', $plate, 'nameplate', 21);
            $view->protect($plate);
            $view->prose('dialogue-speaker', $line->speaker,
                new CanvasRectangle($x + 16, $plate->y + 12, $nameWidth - 32, $nameHeight - 24), 'focus', HorizontalAlignment::CENTER);
        }
        if ($portrait !== null) {
            $dock = $geometry->portrait;
            $view->surface('dialogue-portrait-backing', new CanvasRectangle($dock->x + 8, $dock->y + 8,
                $dock->width - 16, $dock->height - 16), 'panel', 20);
            $view->frame('dialogue-portrait-frame', $dock, 'portrait', 21);
            $view->protect($dock);
            array_push($images, ...MenuIconRegistry::containAsset($theme->assetRoot, 'dialogue-portrait', $portrait,
                new CanvasRectangle($dock->x + 8, $dock->y + 8, $dock->width - 16, $dock->height - 16), 22, $dock));
        }
        $prose = self::renderText($line, new CanvasRectangle($x, $top + $padding, $textWidth, $textHeight),
            $m->cellWidth, $m->cellHeight, $theme->colors['text']);
        $footerY = $top + $bodyHeight - $padding - $m->rowHeight;
        if ($line->help !== '') {
            $view->prose('dialogue-authored-help', $line->help,
                new CanvasRectangle($x, $top + $padding + $textHeight, $textWidth, $helpHeight), 'disabled');
        }
        if ($line->pageCount > 1) {
            $view->renderBorderCaption('dialogue-page', ($line->pageIndex + 1) . ' / ' . $line->pageCount,
                new CanvasRectangle($x, $top + $bodyHeight - 20, $textWidth, 16));
        }
        if ($line->interactive) {
            $view->surface('dialogue-footer-rule', new CanvasRectangle($x, $footerY - 8, $textWidth, 1), 'edge', 20);
            $autoWidth = max(120, 9 * $m->cellWidth + 2 * $theme->rows->metrics->padding);
            $view->rows('dialogue-auto', [new MenuRow('auto', 'Auto ' . ($line->auto ? 'On' : 'Off'), kind: MenuRowKind::BUTTON,
                selected: $line->auto, showCursor: false)],
                new MenuRowLayout(new CanvasRectangle($x, $footerY, $autoWidth, $m->rowHeight),
                    rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight));
            // Each control sits on the footer row's centre line, as the Auto button's label does.
            $autoHint = $view->renderControl('dialogue-auto-hint', ActionHints::resolveControl('dialogue_auto'),
                new CanvasRectangle($x + $autoWidth + 8, $footerY, $textWidth - $autoWidth - 8, $m->rowHeight), HorizontalAlignment::LEFT);
            // The advance key alone: it reads the same while typing and when waiting, so nothing in the footer changes.
            $advanceLeft = $autoHint->x + $autoHint->width + 16;
            $view->renderControl('dialogue-advance', ActionHints::resolveControl('confirm'),
                new CanvasRectangle($advanceLeft, $footerY, $x + $textWidth - 24 - $advanceLeft, $m->rowHeight), HorizontalAlignment::RIGHT);
            if (!$line->isPrinting) {
                $view->icon('dialogue-ready', 'navigation.continue', new CanvasRectangle($width - $margin - $padding - 18,
                    $footerY + ($m->rowHeight - 18) / 2, 18, 18));
            }
        }
        $canvas = $view->finish();
        \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight::inspect([...$images, ...$canvas->images], $theme->assetRoot);
        // An ordinary dialogue has no whole-scene fill; skits own a full scene surface.
        $text = $skit ? $canvas->textLayers : array_values(array_filter($canvas->textLayers,
            static fn($layer) => $layer->id !== 'menu-background' && !str_starts_with($layer->id, 'menu-background-part-')));
        return new PresentationCanvas($width, $height, [...$images, ...$canvas->images], textLayers: [...$text, $prose],
            protectedAreas: $canvas->getOverlayProtection());
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
                $column = match ($line->textAlignment) {
                    HorizontalAlignment::LEFT => 0,
                    HorizontalAlignment::CENTER => max(0, intdiv($columns - mb_strlen($wrapped), 2)),
                    HorizontalAlignment::RIGHT => max(0, $columns - mb_strlen($wrapped)),
                };
                if ($text !== '') { $runs[] = new PresentationTextRun($row, $column, $text, $color); }
                $remaining -= mb_strlen($wrapped);
                $row++;
            }
            $remaining--; // Consume the authored paragraph separator, not visual wrap breaks.
        }
        return new CanvasTextLayer('dialogue-text', 30, $bounds->x, $bounds->y,
            new RendererGridConfig($columns, $row, $cellWidth, $cellHeight), $runs, $bounds);
    }
}
