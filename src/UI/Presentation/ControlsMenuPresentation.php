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
    $lookup = $active === null ? 'No rebindable actions.' : 'Keyboard bindings: ' . $active['keys'];
    $info = $lookup . ($content->status === '' ? '' : "\n" . $content->status);
    $infoHeight = max(80, 2 * $p + count(MenuCanvas::wrap($info, $cells)) * $m->cellHeight);
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
    $view->prose('controls-lookup', $info,
      new CanvasRectangle($host->x + $p, $footer->y + $p, $innerWidth, $infoHeight - 2 * $p));
    $bounds = new CanvasRectangle($host->x + $p, $host->y + $p + $m->cellHeight + $gap,
      $innerWidth, $listHeight - 2 * $p - $m->cellHeight - $gap);
    if ($content->rows === []) {
      $view->prose('controls-empty', 'No rebindable actions.', $bounds, 'disabled');
      return $view->finish();
    }
    $glyphCells = max(array_map(fn($row) => ControlGlyphPresentation::cells($row['control'], $theme), $content->rows));
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
    $images = $text = [];
    $y = $bounds->y;
    for ($i = $first; $i <= $last; $i++) {
      $rowBox = new CanvasRectangle($bounds->x, $y, $bounds->width, $heights[$i]);
      $view->rows('controls-action', [$rows[$i]], new MenuRowLayout($rowBox, $columns,
        $m->rowHeight, $m->cellWidth, $m->cellHeight, true));
      $glyphX = $bounds->x + $theme->rows->metrics->padding
        + ($layout->textCells($theme->rows->metrics) - $glyphCells - $descriptionCells
          - $theme->rows->metrics->gapCells) * $m->cellWidth;
      $glyph = ControlGlyphPresentation::compose($width, $height, 'controls-glyph-' . $i,
        $content->rows[$i]['control'], $theme, new CanvasRectangle($glyphX, $y, $glyphCells * $m->cellWidth, $heights[$i]));
      array_push($images, ...$glyph->images);
      array_push($text, ...$glyph->textLayers);
      $y += $heights[$i];
    }
    return MenuCanvas::overlay($view->finish(), new PresentationCanvas($width, $height, $images, textLayers: $text), $theme);
  }
}
