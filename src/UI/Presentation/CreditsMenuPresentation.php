<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** A clipped, centered roll using the same panel, typography and button theme as other menus. */
final class CreditsMenuPresentation
{
  public static function getViewport(MenuPresentationCatalog $theme): CanvasRectangle
  {
    $bounds = MenuLayout::getBounds();
    $m = $theme->metrics;
    $padding = max($m->panelPadding, $m->sectionGap);
    return new CanvasRectangle($bounds->x + $padding,
      $bounds->y + $padding + $m->cellHeight + $m->sectionGap,
      $bounds->width - 2 * $padding,
      $bounds->height - 2 * $padding - $m->cellHeight - $m->rowHeight - 2 * $m->sectionGap);
  }

  public static function createPlayback(CreditsContent $content, MenuPresentationCatalog $theme): CreditsPlayback
  {
    $viewport = self::getViewport($theme);
    $m = $theme->metrics;
    $lines = $content->getLines((int)floor($viewport->width / $m->cellWidth));
    return new CreditsPlayback($viewport->height + count($lines) * $m->cellHeight, $m->cellHeight * 0.9);
  }

  public static function compose(CreditsContent $content, CreditsPlayback $playback, MenuPresentationCatalog $theme): PresentationCanvas
  {
    $view = new MenuCanvas($theme);
    $m = $theme->metrics;
    $bounds = MenuLayout::getBounds();
    $viewport = self::getViewport($theme);
    $view->frame('credits-frame', $bounds);
    $view->prose('credits-heading', get_message('title.credits', 'Credits'),
      new CanvasRectangle($viewport->x, $bounds->y + max($m->panelPadding, $m->sectionGap), $viewport->width, $m->cellHeight),
      'accent', HorizontalAlignment::CENTER);
    $label = get_message('menu.back', 'Back');
    $buttonWidth = MenuLayout::getButtonWidth($theme, $label, $viewport->width);
    $view->rows('credits-back', [new MenuRow('back', $label, kind: MenuRowKind::BUTTON,
      selected: true, focused: true, showCursor: false)],
      new MenuRowLayout(new CanvasRectangle($bounds->x + ($bounds->width - $buttonWidth) / 2,
        $viewport->y + $viewport->height + $m->sectionGap, $buttonWidth, $m->rowHeight),
        rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight));
    $base = $view->finish();
    $columns = (int)floor($viewport->width / $m->cellWidth);
    $lines = $content->getLines($columns);
    $origin = $viewport->y + $viewport->height - $playback->offset;
    $first = max(0, (int)floor(($viewport->y - $origin) / $m->cellHeight));
    $end = min(count($lines), (int)ceil(($viewport->y + $viewport->height - $origin) / $m->cellHeight));
    if ($end <= $first) { return $base; }
    $runs = [];
    for ($index = $first; $index < $end; $index++) {
      $line = $lines[$index];
      if ($line['text'] === '') { continue; }
      $runs[] = new PresentationTextRun($index - $first, intdiv($columns - mb_strlen($line['text']), 2),
        $line['text'], $theme->colors[$line['color']]);
    }
    $text = new CanvasTextLayer('credits-roll', 30,
      $viewport->x + ($viewport->width - $columns * $m->cellWidth) / 2, $origin + $first * $m->cellHeight,
      new RendererGridConfig($columns, $end - $first, $m->cellWidth, $m->cellHeight), $runs, $viewport);
    return new PresentationCanvas($base->width, $base->height, $base->images, $base->indicators, [...$base->textLayers, $text]);
  }
}
