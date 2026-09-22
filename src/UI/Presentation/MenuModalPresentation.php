<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Menu-local modal projection. Existing modal owners retain input, selection and outcomes. */
final class MenuModalPresentation
{
  public static function compose(PresentationCanvas $base, ModalPresentation $modal,
    MenuPresentationCatalog $theme, float $time = 0): PresentationCanvas
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $longest = static fn(string $text): int => max(array_map(mb_strlen(...), explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text))));
    $buttonWidths = array_map(fn(string $label) => $longest($label) * $m->cellWidth + 2 * $theme->rows->metrics->padding, $modal->choices);
    $choicesWidth = $modal->singleConfirmation ? 2 * $buttonWidths[0]
      : ($modal->vertical ? max($buttonWidths) : array_sum($buttonWidths) + (count($buttonWidths) - 1) * $gap);
    $naturalWidth = max(36 * $m->cellWidth, $longest($modal->title) * $m->cellWidth,
      $longest($modal->message) * $m->cellWidth, $choicesWidth);
    if ($modal->quantity !== null) {
      $naturalWidth = max($naturalWidth, (2 * strlen((string)$modal->quantity->maximum) + 3) * $m->cellWidth
        + 2 * ($m->cellHeight + $gap));
    }
    $width = (int)floor(min($base->width * 2 / 3 - 2 * $p, $naturalWidth) / $m->cellWidth) * $m->cellWidth;
    $x = ($base->width - $width) / 2;
    $cells = (int)floor($width / $m->cellWidth);
    $titleHeight = $modal->title === '' ? 0 : count(MenuCanvas::wrap($modal->title, $cells)) * $m->cellHeight;
    $messageHeight = $modal->message === '' ? 0 : count(MenuCanvas::wrap($modal->message, $cells)) * $m->cellHeight;
    $bodyHeight = $modal->singleConfirmation ? max($messageHeight, 3 * $m->cellHeight) : $messageHeight;
    $quantityHeight = $modal->quantity === null ? 0 : 2 * $m->cellHeight + $gap;
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
    $hintHeight = MenuActionHints::height($hints, $theme, $width);
    $hintGap = $hintHeight > 0 ? $gap : 0;
    $available = $base->height - 4 * $p - $titleHeight - $bodyHeight - $quantityHeight - $hintHeight - 2 * $gap - $hintGap;
    if ($available < $m->rowHeight + $m->cellHeight) {
      throw new RuntimeException('Modal content exceeds its finite menu viewport; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $base->width, $base->height, $time);
    $rows = [];
    foreach ($modal->choices as $index => $choice) {
      $rows[] = new MenuRow((string)$index, $choice,
        kind: $modal->vertical ? MenuRowKind::COMMAND : MenuRowKind::BUTTON,
        selected: $index === $modal->activeIndex, focused: $index === $modal->activeIndex);
    }
    $singleButton = !$modal->vertical && count($rows) === 1;
    $buttonWidth = $singleButton ? MenuLayout::getButtonWidth($theme, $modal->choices[0], $width)
      : ($modal->vertical ? $width : ($width - (count($rows) - 1) * $gap) / count($rows));
    if ($buttonWidth <= 2 * $theme->rows->metrics->padding) {
      throw new RuntimeException('Modal buttons require a wider menu viewport.');
    }
    $layout = new MenuRowLayout(new CanvasRectangle(0, 0, $buttonWidth, $available), rowHeight: $m->rowHeight,
      cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
    $heights = array_map(fn(MenuRow $row) => $layout->heightFor($row, $theme->rows->metrics, false), $rows);
    $choiceHeight = $modal->vertical ? min($available, array_sum($heights)) : max($heights);
    if ($choiceHeight > $available) { throw new RuntimeException('Modal button text exceeds its finite menu viewport.'); }
    $height = 2 * $p + $titleHeight + $bodyHeight + $quantityHeight + $choiceHeight + $hintHeight + 2 * $gap + $hintGap;
    $top = ($base->height - $height) / 2;
    $box = new CanvasRectangle($x - $p, $top, $width + 2 * $p, $height);
    $view->frame('menu-modal-frame', $box);
    $y = $top + $p;
    if ($titleHeight > 0) {
      $view->prose('menu-modal-title', $modal->title, new CanvasRectangle($x, $y, $width, $titleHeight),
        alignment: HorizontalAlignment::CENTER);
    }
    $y += $titleHeight + $gap;
    if ($messageHeight > 0) {
      $view->prose('menu-modal-message', $modal->message,
        new CanvasRectangle($x, $y + ($bodyHeight - $messageHeight) / 2, $width, $messageHeight),
        alignment: $modal->singleConfirmation || $messageHeight === $m->cellHeight
          ? HorizontalAlignment::CENTER : HorizontalAlignment::LEFT);
    }
    $y += $bodyHeight + $gap;
    $controls = new MenuControls($theme);
    if ($modal->quantity !== null) {
      $quantity = $modal->quantity;
      $center = $base->width / 2;
      $valueWidth = (2 * strlen((string)$quantity->maximum) + 3) * $m->cellWidth;
      $left = $center - $valueWidth / 2;
      $view->prose('menu-modal-quantity', $quantity->value . ' / ' . $quantity->maximum,
        new CanvasRectangle($left, $y + $m->cellHeight / 2, $valueWidth, $m->cellHeight), alignment: HorizontalAlignment::CENTER);
      $arrowX = $left + $valueWidth + $gap;
      $controls->renderChevron('menu-modal-quantity-up', MenuDirection::UP->value,
        new CanvasRectangle($arrowX, $y, $m->cellHeight, $m->cellHeight), $quantity->value < $quantity->maximum);
      $controls->renderChevron('menu-modal-quantity-down', MenuDirection::DOWN->value,
        new CanvasRectangle($arrowX, $y + $m->cellHeight, $m->cellHeight, $m->cellHeight), $quantity->value > $quantity->minimum);
      $y += $quantityHeight;
    }
    if ($modal->vertical) {
      $view->rows('menu-modal-choice', $rows, new MenuRowLayout(new CanvasRectangle($x, $y, $width, $choiceHeight),
        rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true), $modal->activeIndex);
    } else {
      foreach ($rows as $index => $row) {
        $view->rows('menu-modal-choice', [$row], new MenuRowLayout(
          new CanvasRectangle($x + ($singleButton ? ($width - $buttonWidth) / 2 : $index * ($buttonWidth + $gap)),
            $y, $buttonWidth, $choiceHeight),
          rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
      }
    }
    if ($hintHeight > 0) {
      $view->hints('menu-modal-hints', $hints, new CanvasRectangle($x, $y + $choiceHeight + $hintGap, $width, $hintHeight));
    }
    $dialog = MenuCanvas::overlay($view->finish(), $controls->finish($base->width, $base->height), $theme);
    return MenuCanvas::overlay($base, $dialog, $theme);
  }
}
