<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Text\MenuInfoText;

/** A stable two-line description/status slot. Hints occupy separately reserved space. */
final class MenuInfoPanel
{
  public static function getHeight(MenuPresentationCatalog $theme, array $hints = [], float $width = 1100): int
  {
    $m = $theme->metrics;
    $hintHeight = MenuActionHints::height($hints, $theme, $width - 2 * $m->panelPadding);
    return 2 * $m->panelPadding + 2 * $m->cellHeight + $hintHeight + ($hintHeight > 0 ? $m->sectionGap : 0);
  }

  public static function renderContent(MenuCanvas $view, string $id, CanvasRectangle $bounds, string $description,
    ?string $status = null, string $statusColor = 'accent', ?MenuInfoText $infoModel = null,
    ?CanvasRectangle $rangeBounds = null): void
  {
    $m = $view->theme->metrics;
    $cells = (int)floor($bounds->width / $m->cellWidth);
    $page = ($infoModel ?? new MenuInfoText())->getPage($description, $status, $cells);
    $descriptionRows = $description === '' ? 0 : (new MenuInfoText())->getPage($description, null, $cells)->total;
    $split = max(0, min(count($page->lines), $descriptionRows - $page->first));
    if ($split > 0) {
      $view->textLines($id . '-description', array_map(static fn(string $line) => ['text' => $line, 'color' => 'text'],
        array_slice($page->lines, 0, $split)),
        new CanvasRectangle($bounds->x, $bounds->y, $bounds->width, $split * $m->cellHeight));
    }
    if ($split < count($page->lines)) {
      $view->textLines($id . '-status', array_map(static fn(string $line) => ['text' => $line, 'color' => $statusColor],
        array_slice($page->lines, $split)),
        new CanvasRectangle($bounds->x, $bounds->y + $split * $m->cellHeight,
          $bounds->width, (2 - $split) * $m->cellHeight));
    }
    if ($page->total > 2 && $rangeBounds !== null) {
      $view->renderBorderCaption($id . '-info-range', $page->range(), $rangeBounds);
    }
  }
}
