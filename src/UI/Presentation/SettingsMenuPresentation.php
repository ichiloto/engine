<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Shared settings drawing for Main Menu, Pause and Title; owners retain selection and persistence. */
final class SettingsMenuPresentation
{
  public static function compose(SettingsMenuContent $menu, MenuPresentationCatalog $theme, float $time = 0,
    int $width = 1350, int $height = 720): PresentationCanvas
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $box = $menu->bounds ?? MenuLayout::getBounds($width, $height);
    $box->assertWithin($width, $height);
    $x = $box->x + $p;
    $innerWidth = $box->width - 2 * $p;
    $setting = $menu->settings[$menu->activeIndex] ?? null;
    $description = $setting?->description ?? ($menu->backFocused ? 'Return to the previous menu.' : 'No settings available.');
    $status = $menu->status ?? '';
    $detailHeight = 3 * $m->cellHeight + $gap;
    $cancelWidth = MenuLayout::getButtonWidth($theme, $menu->backLabel, $innerWidth);
    $hints = [ActionHints::resolve('confirm', 'Next'), ActionHints::resolve('cancel', 'Cancel')];
    $hintWidth = $innerWidth - $cancelWidth - $gap;
    $hintHeight = MenuActionHints::height($hints, $theme, $hintWidth);
    $footerHeight = max($m->rowHeight, $hintHeight);
    $listTop = $box->y + $p + $m->rowHeight + 2 * $gap;
    $detailTop = $box->y + $box->height - $p - $footerHeight - $gap - $detailHeight;
    $listHeight = $detailTop - $gap - $listTop;
    if ($listHeight < $m->rowHeight + $m->cellHeight) {
      throw new RuntimeException('Config description/status leaves no complete setting row in its finite viewport; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $width, $height, $time);
    $valuesView = new MenuCanvas($theme, $width, $height, $time);
    $controls = new MenuControls($theme);
    $view->frame('config-frame', $box);
    $view->prose('config-title', $menu->title, new CanvasRectangle($x, $box->y + $p, $innerWidth, $m->rowHeight),
      alignment: HorizontalAlignment::CENTER);
    $controls->renderDivider('config-divider', new CanvasRectangle($x, $listTop - 2 * $gap, $innerWidth, max(1, $gap)));
    $settings = $menu->settings;
    $rail = $m->cellHeight;
    $listBox = new CanvasRectangle($x, $listTop, $innerWidth - $m->scrollbarWidth - $gap, $listHeight);
    $valueCells = max(1, (int)floor(($listBox->width - 2 * $theme->rows->metrics->padding) * 0.44 / $m->cellWidth));
    $layout = new MenuRowLayout($listBox, [new MenuRowColumn($valueCells, HorizontalAlignment::CENTER)],
      $m->rowHeight, $m->cellWidth, $m->cellHeight, true);
    $layout->assertFits($theme->rows->metrics);
    $rows = $heights = $values = [];
    $index = $menu->activeIndex;
    foreach ($settings as $position => $entry) {
      $choices = array_keys($entry->choices);
      $choiceIndex = $menu->choiceIndices[$position];
      $value = (string)($choices[$choiceIndex] ?? '');
      $valueWidth = $valueCells * $m->cellWidth - 2 * ($rail + $gap);
      if ($valueWidth < $m->cellWidth) { throw new RuntimeException('Config values require a wider finite viewport.'); }
      $valueHeight = count(MenuCanvas::wrap($value, (int)floor($valueWidth / $m->cellWidth))) * $m->cellHeight;
      $rows[] = new MenuRow((string)$position, $entry->label, [new MenuRowValue('')],
        selected: $position === $index, focused: $position === $index);
      $heights[] = max($layout->heightFor($rows[$position], $theme->rows->metrics, false),
        $m->rowHeight + $valueHeight - $m->cellHeight);
      $values[] = [$value, $choiceIndex, $valueHeight];
    }
    $rangeBox = new CanvasRectangle($x + $innerWidth * 2 / 3, $box->y + $p, $innerWidth / 3, $m->cellHeight);
    [$first, $last] = $rows === [] ? [0, -1] : $view->visibleRange('config-settings', $heights, $listBox, $index, $rangeBox);
    if (array_sum($heights) <= $listHeight) {
      $view->prose('config-settings-range', $rows === [] ? '0 / 0' : '1-' . count($rows) . ' / ' . count($rows),
        $rangeBox, 'disabled', HorizontalAlignment::RIGHT);
    }
    $accent = SgrColorParser::parse(SelectionStyle::resolveColor()->value . ' ')['foreground'] ?? $theme->colors['accent'];
    $y = $listTop;
    for ($i = $first; $i <= $last; $i++) {
      $bounds = new CanvasRectangle($x, $y, $listBox->width, $heights[$i]);
      $labelGrowth = $layout->heightFor($rows[$i], $theme->rows->metrics, false) - $m->rowHeight;
      $view->rows('config-setting', [$rows[$i]], new MenuRowLayout($bounds, $layout->columns,
        $heights[$i] - $labelGrowth, $m->cellWidth, $m->cellHeight, true));
      [$value, $choiceIndex, $valueHeight] = $values[$i];
      $entry = $settings[$i];
      $valueX = $bounds->x + $bounds->width - $theme->rows->metrics->padding - $valueCells * $m->cellWidth;
      $arrowY = $y + ($heights[$i] - $rail) / 2;
      $controls->renderChevron('config-previous-' . $i, 'previous', new CanvasRectangle($valueX, $arrowY, $rail, $rail),
        count($entry->choices) > 1 && ($entry->wraps || $choiceIndex > 0));
      $controls->renderChevron('config-next-' . $i, 'next', new CanvasRectangle($valueX + $valueCells * $m->cellWidth - $rail, $arrowY, $rail, $rail),
        count($entry->choices) > 1 && ($entry->wraps || $choiceIndex < count($entry->choices) - 1));
      $valueBox = new CanvasRectangle($valueX + $rail + $gap, $y + ($heights[$i] - $valueHeight) / 2,
        $valueCells * $m->cellWidth - 2 * ($rail + $gap), $valueHeight);
      $numeric = !$entry->wraps && count($entry->choices) > 1
        && array_all($entry->choices, static fn($choice) => is_int($choice) || is_float($choice));
      $numericWidth = mb_strlen($value) * $m->cellWidth;
      if ($numeric && $valueBox->width - $numericWidth - $gap > 2 * $rail) {
        $controls->renderLevel('config-level-' . $i, new CanvasRectangle($valueBox->x, $arrowY,
          $valueBox->width - $numericWidth - $gap, $rail), $choiceIndex / (count($entry->choices) - 1), $accent);
        $valueBox = new CanvasRectangle($valueBox->x + $valueBox->width - $numericWidth, $valueBox->y, $numericWidth, $valueHeight);
      }
      $valuesView->prose('config-value-' . $i, $value, $valueBox, alignment: HorizontalAlignment::CENTER);
      if ($i === $index && $theme->rows->metrics->accentWidth > 0) {
        $edge = $theme->rows->metrics->focusWidth;
        $controls->fill('config-selection-accent', new CanvasRectangle($x + $edge, $y + $edge,
          $theme->rows->metrics->accentWidth, $heights[$i] - 2 * $edge), $accent);
      }
      $y += $heights[$i];
    }
    if ($last + 1 < count($rows) || $first > 0) {
      $controls->renderScrollbar('config-scroll',
        new CanvasRectangle($x + $innerWidth - $m->scrollbarWidth, $listTop, $m->scrollbarWidth, $listHeight),
        array_sum(array_slice($heights, 0, $first)), $y - $listTop, array_sum($heights));
    }
    $controls->renderDivider('config-description-divider', new CanvasRectangle($x, $detailTop - $gap, $innerWidth, max(1, $gap / 2)));
    $view->prose('config-description-title', 'Description', new CanvasRectangle($x, $detailTop, $innerWidth, $m->cellHeight), 'accent');
    MenuInfoPanel::renderContent($view, 'config',
      new CanvasRectangle($x, $detailTop + $m->cellHeight + $gap, $innerWidth, 2 * $m->cellHeight),
      $description, $status, $menu->statusError ? 'decrease' : 'disabled', $menu->info,
      new CanvasRectangle($x + $innerWidth / 2, $detailTop, $innerWidth / 2, $m->cellHeight));
    $footerY = $box->y + $box->height - $p - $footerHeight;
    if ($hintHeight > 0) {
      $view->hints('config-hints', $hints, new CanvasRectangle($x, $footerY, $hintWidth, $hintHeight));
    }
    $view->rows('config', [new MenuRow('cancel', $menu->backLabel, kind: MenuRowKind::BUTTON,
      selected: $menu->backFocused, focused: $menu->backFocused)],
      new MenuRowLayout(new CanvasRectangle($x + $innerWidth - $cancelWidth, $footerY, $cancelWidth, $m->rowHeight),
        rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight));
    // Value text and controls sit above the shared row's selection artwork, not behind it.
    $frame = MenuCanvas::overlay($view->finish(), $valuesView->finish(), $theme);
    return MenuCanvas::overlay($frame, $controls->finish($width, $height), $theme);
  }
}
