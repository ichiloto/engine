<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** Static scene staging uses the same actor/emotion roles as ordinary dialogue. */
final class SkitCanvasPresentation
{
    public static function renderStage(MenuCanvas $view, DialogueSnapshot $line,
        DialoguePresentationCatalog $catalogue, CanvasRectangle $dialogue, bool $supportsImageTone = true): array
    {
        $context = $line->context;
        $images = [];
        $root = $view->theme->assetRoot;
        $background = $catalogue->skits[$context->skitId]['background'] ?? null;
        $size = $background === null ? null : PngAssetPreflight::getAvailableSize($root, $background);
        if ($size !== null) {
            $scale = max($view->width / $size['width'], $view->height / $size['height']);
            $w = min($size['width'], (int)ceil($view->width / $scale));
            $h = min($size['height'], (int)ceil($view->height / $scale));
            $images[] = new CanvasImage('skit-background', $background,
                new CanvasRectangle(0, 0, $view->width, $view->height), 1,
                new SpriteSourceRect(intdiv($size['width'] - $w, 2), intdiv($size['height'] - $h, 2), $w, $h));
        }
        $m = $view->theme->metrics;
        $titleWidth = min($view->width - 80, max(320,
            max(mb_strlen($context->skitTitle), mb_strlen($context->location)) * $m->cellWidth + 40));
        $titleColumns = (int)floor(($titleWidth - 32) / $m->cellWidth);
        $titleLines = count(MenuCanvas::wrap($context->skitTitle, $titleColumns));
        $locationLines = $context->location === '' ? 0 : count(MenuCanvas::wrap($context->location, $titleColumns));
        $titleHeight = ($titleLines + $locationLines) * $m->cellHeight + 24;
        $title = new CanvasRectangle(($view->width - $titleWidth) / 2, 24, $titleWidth, $titleHeight);
        $view->frame('skit-title-frame', $title, 'quiet');
        $view->prose('skit-title', $context->skitTitle,
            new CanvasRectangle($title->x + 16, $title->y + 12, $titleWidth - 32, $titleLines * $m->cellHeight),
            'text', HorizontalAlignment::CENTER);
        if ($context->location !== '') {
            $view->prose('skit-location', $context->location,
                new CanvasRectangle($title->x + 16, $title->y + 12 + $titleLines * $m->cellHeight,
                    $titleWidth - 32, $locationLines * $m->cellHeight),
                'focus', HorizontalAlignment::CENTER);
        }
        $participants = $context->participants;
        // Larger casts use stable groups rather than shrinking every bust indefinitely.
        if (count($participants) > 3) {
            $active = array_search($context->actorId, array_column($participants, 'actorId'), true);
            $participants = array_slice($participants, 3 * intdiv($active === false ? 0 : $active, 3), 3);
        }
        $count = count($participants);
        $slotWidth = ($view->width - 80) / max(1, $count);
        $stageTop = $title->y + $title->height + 12;
        $stageBottom = $dialogue->y;
        foreach ($participants as $index => $participant) {
            $id = $participant['actorId'];
            $asset = $catalogue->getArtwork($id, $participant['emotion'], 'bust');
            $source = $asset === null ? null : PngAssetPreflight::getAvailableSize($root, $asset);
            $x = 40 + $index * $slotWidth;
            $active = $id === $context->actorId;
            if ($source !== null && $stageBottom > $stageTop) {
                // Emphasis changes visual size, never the slot centre or shared baseline.
                $scale = min(($slotWidth - 12) / $source['width'], ($stageBottom - $stageTop) / $source['height'])
                    * ($active ? 1 : $catalogue->skitStage->inactiveScale);
                $w = $source['width'] * $scale;
                $h = $source['height'] * $scale;
                $images[] = new CanvasImage('skit-bust-' . $index, $asset,
                    new CanvasRectangle($x + ($slotWidth - $w) / 2, $stageBottom - $h, $w, $h), 5,
                    brightness: $active || !$supportsImageTone ? 1 : $catalogue->skitStage->inactiveBrightness);
            }
        }
        return $images;
    }
}
