<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/** Theme-owned pager presentation. The menu owner handles semantic page actions. */
final class MenuPager
{
  public const string PREVIOUS_ACTION = 'menu_page_previous';
  public const string NEXT_ACTION = 'menu_page_next';

  public static function getWidth(MenuPresentationCatalog $theme, int $maxPages): int
  {
    if ($maxPages < 1) { throw new InvalidArgumentException('A menu pager requires at least one page.'); }
    return (int)ceil(MenuLayout::getButtonWidth($theme, 'Previous', PHP_INT_MAX)
      + MenuLayout::getButtonWidth($theme, 'Next', PHP_INT_MAX)
      + mb_strlen(sprintf('Page %d of %d', $maxPages, $maxPages)) * $theme->metrics->cellWidth
      + 2 * $theme->metrics->sectionGap);
  }

  public static function getHeight(MenuPresentationCatalog $theme): int
  {
    $m = $theme->metrics;
    return (int)ceil(max($m->cellHeight + $theme->rows->metrics->separatorWidth,
      min($m->rowHeight, $m->cellHeight + $m->sectionGap / 3), $theme->rows->metrics->cursorHeight));
  }

  public static function render(MenuCanvas $view, string $id, MenuPagination $pagination,
    int $pageIndex, CanvasRectangle $bounds): void
  {
    $count = count($pagination->pages);
    if ($pageIndex < 0 || $pageIndex >= $count || $bounds->width < self::getWidth($view->theme, $count)
      || $bounds->height < self::getHeight($view->theme)) {
      throw new InvalidArgumentException('A menu pager must fit its measured page controls and counter.');
    }
    $theme = $view->theme;
    $m = $theme->metrics;
    $previousWidth = MenuLayout::getButtonWidth($theme, 'Previous', $bounds->width);
    $nextWidth = MenuLayout::getButtonWidth($theme, 'Next', $bounds->width);
    $height = self::getHeight($theme);
    foreach (['previous' => [$bounds->x, $previousWidth, 'Previous', $pageIndex === 0],
      'next' => [$bounds->x + $bounds->width - $nextWidth, $nextWidth, 'Next', $pageIndex === $count - 1]] as $role => [$x, $width, $label, $disabled]) {
      $box = new CanvasRectangle($x, $bounds->y, $width, $height);
      // Compact controls use theme-colored edges, not a compressed full-panel artwork frame.
      $view->renderOutline($id . '-' . $role, $box, 'edge', max(1, $theme->rows->metrics->focusWidth));
      $view->rows($id . '-' . $role, [new MenuRow('button', $label, kind: MenuRowKind::BUTTON,
        disabled: $disabled, showCursor: false)], new MenuRowLayout($box, rowHeight: $height,
        cellWidth: $m->cellWidth, cellHeight: $m->cellHeight));
    }
    $view->prose($id . '-counter', sprintf('Page %d of %d', $pageIndex + 1, $count),
      new CanvasRectangle($bounds->x + $previousWidth + $m->sectionGap, $bounds->y + ($height - $m->cellHeight) / 2,
        $bounds->width - $previousWidth - $nextWidth - 2 * $m->sectionGap, $m->cellHeight),
      'accent', HorizontalAlignment::CENTER);
  }
}
