<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use RuntimeException;

/** Presentation only: the existing GameOver menu owns commands and navigation. */
final class GameOverMenuPresentation
{
  /** @param list<MenuRow> $commands */
  public static function compose(MenuPresentationCatalog $theme, string $title, array $commands): PresentationCanvas
  {
    $view = new MenuCanvas($theme);
    $m = $theme->metrics;
    $p = max(20, $m->panelPadding);
    $gap = max(12, $m->sectionGap);
    $width = min($view->width - 4 * $p, max(480, 40 * $m->cellWidth + 2 * $p));
    $contentWidth = $width - 2 * $p;
    $titleCells = mb_strlen($title, 'UTF-8');
    if ($titleCells < 1 || $titleCells > $contentWidth / $m->cellWidth) {
      throw new RuntimeException('Game Over heading exceeds its finite viewport.');
    }
    $titleWidth = min(3 * $m->cellWidth, (int)floor($contentWidth / $titleCells));
    $titleHeight = (int)round($m->cellHeight * $titleWidth / $m->cellWidth);
    $rowHeight = max($m->rowHeight, 2 * $m->cellHeight);
    $layout = new MenuRowLayout(new CanvasRectangle(0, 0, $contentWidth, $rowHeight),
      rowHeight: $rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
    $heights = array_map(fn(MenuRow $row) => $layout->heightFor($row, $theme->rows->metrics, false), $commands);
    $hints = [ActionHints::resolve('confirm', 'Confirm')];
    $hintHeight = MenuActionHints::height($hints, $theme, $contentWidth);
    $height = 2 * $p + $titleHeight + 2 * $gap + 8 + array_sum($heights)
      + max(0, count($commands) - 1) * $gap + ($hintHeight > 0 ? $gap + $hintHeight : 0);
    if ($height > $view->height - 2 * $p) {
      throw new RuntimeException('Game Over commands exceed their finite viewport.');
    }
    $x = ($view->width - $width) / 2;
    $y = ($view->height - $height) / 2;
    $panel = new CanvasRectangle($x, $y, $width, $height);
    $view->frame('game-over-panel', $panel);
    $view->protect($panel);
    $heading = new CanvasTextLayer('game-over-heading', 30,
      ($view->width - $titleCells * $titleWidth) / 2, $y + $p,
      new RendererGridConfig($titleCells, 1, $titleWidth, $titleHeight),
      [new PresentationTextRun(0, 0, $title, $theme->colors['accent'])]);
    $y += $p + $titleHeight + $gap;
    $divider = new CanvasRectangle($x + $p, $y, $contentWidth, 8);
    if (!$view->icon('game-over-divider', 'decoration.divider', $divider)) {
      $view->surface('game-over-divider', new CanvasRectangle($divider->x, $y + 3, $contentWidth, 2), 'edge');
    }
    $y += 8 + $gap;
    foreach ($commands as $index => $command) {
      $view->rows('game-over', [$command], new MenuRowLayout(
        new CanvasRectangle($x + $p, $y, $contentWidth, $heights[$index]),
        rowHeight: $rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
      $y += $heights[$index] + $gap;
    }
    if ($hintHeight > 0) {
      $view->hints('game-over-hints', $hints, new CanvasRectangle($x + $p, $y, $contentWidth, $hintHeight));
    }
    $canvas = $view->finish();
    return new PresentationCanvas($canvas->width, $canvas->height, $canvas->images,
      textLayers: [...$canvas->textLayers, $heading], protectedAreas: $canvas->getOverlayProtection());
  }
}
