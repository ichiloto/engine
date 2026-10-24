<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Complete binding lookup over the existing input owner, not an input or device implementation. */
final class ControlsMenuPresentation
{
  // A compact binding lookup leaves more scenery visible than full-page menus.
  private const int MAX_HEIGHT = 560;
  // Description prose receives just under half; action names and glyphs share the rest.
  private const float DESCRIPTION_WIDTH_SHARE = 0.48;
  private const string BINDINGS_LABEL = 'Keyboard bindings:';
  public static function compose(ControlsMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $host = MenuLayout::getBounds($width, $height, self::MAX_HEIGHT);
    $innerWidth = $host->width - 2 * $p;
    $cells = (int)floor($innerWidth / $m->cellWidth);
    $active = $content->rows[$content->index] ?? null;
    $lookup = $active === null ? 'No rebindable actions.' : self::BINDINGS_LABEL;
    $statusHeight = $content->status === '' ? 0 : count(MenuCanvas::wrap($content->status, $cells)) * $m->cellHeight;
    $hintHeight = MenuActionHints::height($content->hints, $theme, $innerWidth, required: true);
    $infoHeight = max(80, 2 * $p + $m->cellHeight + $statusHeight + $hintHeight);
    $listHeight = $host->height - $infoHeight;
    if ($listHeight < 2 * $p + 2 * $m->cellHeight + $gap + $m->rowHeight) {
      throw new RuntimeException('Controls lookup leaves no complete row in its finite viewport; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $width, $height, $time);
    $list = new CanvasRectangle($host->x, $host->y, $host->width, $listHeight);
    $footer = new CanvasRectangle($host->x, $host->y + $listHeight, $host->width, $infoHeight);
    foreach (['controls-list' => $list, 'controls-info' => $footer] as $id => $box) {
      $view->frame($id, $box, $id === 'controls-info' ? 'quiet' : 'panel');
    }
    $view->prose('controls-title', $content->listening ? 'Controls: Rebinding' : 'Controls',
      new CanvasRectangle($host->x + $p, $host->y + $p, $innerWidth, $m->cellHeight), 'accent');
    $view->prose('controls-lookup', $lookup, new CanvasRectangle($host->x + $p, $footer->y + $p, $innerWidth, $m->cellHeight));
    $controls = new ControlGlyphPresentation($theme, 'controls-glyphs');
    if ($active !== null) {
      // Every key bound to the action, each as its keycap, after the label.
      $keyX = $host->x + $p + (mb_strlen(self::BINDINGS_LABEL, 'UTF-8') + 1) * $m->cellWidth;
      foreach ($active['keys'] === [] ? [null] : $active['keys'] as $index => $key) {
        $keyCells = $controls->measureCells($key);
        $box = $controls->renderControl('controls-binding-' . $index, $key, new CanvasRectangle($keyX, $footer->y + $p,
          min($keyCells * $m->cellWidth, $host->x + $host->width - $p - $keyX), $m->cellHeight), HorizontalAlignment::LEFT);
        $keyX = $box->x + $box->width + $m->cellWidth;
      }
    }
    $view->prose('controls-status', $content->status,
      new CanvasRectangle($host->x + $p, $footer->y + $p + $m->cellHeight, $innerWidth, max(1, $statusHeight)));
    $view->hints('controls-hints', $content->hints, new CanvasRectangle($host->x + $p,
      $footer->y + $infoHeight - $p - $hintHeight, $innerWidth, max(1, $hintHeight)), required: true);
    $bounds = new CanvasRectangle($host->x + $p, $host->y + $p + $m->cellHeight + $gap,
      $innerWidth, $listHeight - 2 * $p - $m->cellHeight - $gap);
    if ($content->rows === []) {
      $view->prose('controls-empty', 'No rebindable actions.', $bounds, 'disabled');
      return $view->finish();
    }
    $glyphCells = max(array_map(fn($row) => $controls->measureCells($row['control']), $content->rows));
    $descriptionCells = max(1, (int)floor(($bounds->width - 2 * $theme->rows->metrics->padding) * self::DESCRIPTION_WIDTH_SHARE / $m->cellWidth));
    $columns = [new MenuRowColumn($glyphCells, HorizontalAlignment::CENTER), new MenuRowColumn($descriptionCells, HorizontalAlignment::LEFT)];
    $layout = new MenuRowLayout($bounds, $columns, $m->rowHeight, $m->cellWidth, $m->cellHeight, true);
    $layout->assertFits($theme->rows->metrics);
    $rows = $heights = [];
    foreach ($content->rows as $index => $entry) {
      $row = new MenuRow((string)$index, ucfirst(str_replace('_', ' ', $entry['action'])),
        [new MenuRowValue(''), new MenuRowValue($entry['description'])],
        selected: $index === $content->index, focused: $index === $content->index);
      $rows[] = $row;
      $heights[] = $layout->heightFor($row, $theme->rows->metrics, false);
    }
    [$first, $last] = $view->visibleRange('controls-actions', $heights, $bounds, $content->index);
    $y = $bounds->y;
    for ($i = $first; $i <= $last; $i++) {
      $rowBox = new CanvasRectangle($bounds->x, $y, $bounds->width, $heights[$i]);
      $view->rows('controls-action', [$rows[$i]], new MenuRowLayout($rowBox, $columns,
        $m->rowHeight, $m->cellWidth, $m->cellHeight, true));
      $glyphX = $bounds->x + $theme->rows->metrics->padding
        + ($layout->textCells($theme->rows->metrics) - $glyphCells - $descriptionCells
          - $theme->rows->metrics->gapCells) * $m->cellWidth;
      $controls->renderControl('controls-glyph-' . $i, $content->rows[$i]['control'],
        new CanvasRectangle($glyphX, $y, $glyphCells * $m->cellWidth, $heights[$i]));
      $y += $heights[$i];
    }
    return MenuCanvas::overlay($view->finish(), $controls->compose($width, $height), $theme);
  }
}
