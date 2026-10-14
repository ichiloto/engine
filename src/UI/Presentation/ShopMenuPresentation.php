<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Inventory\Equipment;

use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\SelectShopMenuCommandMode;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Localization\Vocabulary;

/** Skins the existing shop panels; PHP modes still own selection and checkout. */
final class ShopMenuPresentation
{
  public static function compose(ShopState $state, MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas
  {
    if ($state->shopMenu === null || $state->mainPanel === null || $state->detailPanel === null) { return null; }
    $view = new MenuCanvas($theme, time: $time);
    $host = MenuLayout::getBounds();
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $mode = $state->mode;
    $commandMode = $mode instanceof SelectShopMenuCommandMode;
    $quantityMode = $mode instanceof PurchaseConfirmationMode;
    $symbol = Vocabulary::getTerm('currency.symbol', 'G');
    $hints = [ActionHints::resolve('confirm', 'Confirm'),
      ActionHints::resolve($quantityMode ? 'cancel' : 'back', $quantityMode ? 'Cancel' : 'Back'),
      ActionHints::resolve('info', 'Info')];

    $info = MenuInfoPanel::getHeaderBounds($theme, $host, $view->width, $view->height);
    $infoHeight = $info->height;
    $commandHeight = max(60, $m->rowHeight + 2 * min($p, 10), $m->cellHeight + 2 * $p);
    $commandWidth = $host->width * ShopState::COMMAND_PANEL_WIDTH / ShopState::SHOP_MENU_WIDTH;
    $listWidth = $host->width * ShopState::MAIN_PANEL_WIDTH / ShopState::SHOP_MENU_WIDTH;
    $bodyY = $host->y + $infoHeight + $commandHeight;
    $bodyHeight = $host->height - $infoHeight - $commandHeight;

    $view->frame('shop-info', $info, 'quiet');
    MenuInfoPanel::renderHeader($view, 'shop', $info, $state->infoPanel?->text ?? '', $state->menuInfoText);

    $commandBox = new CanvasRectangle($host->x, $info->y + $infoHeight, $commandWidth, $commandHeight);
    $view->frame('shop-commands', $commandBox, 'quiet');
    $commands = $state->shopMenu->getItems()->toArray();
    $width = ($commandWidth - 2 * $p - (count($commands) - 1) * $m->sectionGap) / max(1, count($commands));
    foreach ($commands as $index => $command) {
      $selected = $state->shopMenu->activeIndex === $index;
      $view->rows('shop-commands', [new MenuRow('command-' . $index, $command->getLabel(),
        kind: MenuRowKind::BUTTON, selected: $selected, focused: $selected && $commandMode,
        disabled: $command->isDisabled())], self::layout($theme, new CanvasRectangle(
          $commandBox->x + $p + $index * ($width + $m->sectionGap),
          $commandBox->y + ($commandHeight - $m->rowHeight) / 2, $width, $m->rowHeight)));
    }

    $balanceBox = new CanvasRectangle($host->x + $commandWidth, $commandBox->y,
      $host->width - $commandWidth, $commandHeight);
    $view->frame('shop-balance', $balanceBox, 'quiet');
    $balance = number_format($state->getGameScene()->party->accountBalance) . ' ' . $symbol;
    $view->rows('shop-balance', [new MenuRow('balance', Vocabulary::getTerm('currency.name', 'Gold'),
      [new MenuRowValue($balance)])], self::layout($theme, new CanvasRectangle($balanceBox->x + $p,
        $balanceBox->y + ($commandHeight - $m->rowHeight) / 2, $balanceBox->width - 2 * $p, $m->rowHeight),
        [new MenuRowColumn(mb_strlen($balance))]));

    $listBox = new CanvasRectangle($host->x, $bodyY, $listWidth, $bodyHeight);
    $detailBox = new CanvasRectangle($host->x + $listWidth, $bodyY, $host->width - $listWidth, $bodyHeight);
    $view->frame('shop-items', $listBox);
    $view->frame('shop-details', $detailBox);
    $hintHeight = MenuActionHints::height($hints, $theme, $listWidth - 2 * $p);
    $list = self::inset($listBox, $p);
    $content = new CanvasRectangle($list->x, $list->y, $list->width,
      $list->height - ($hintHeight > 0 ? $hintHeight + $m->sectionGap : 0));
    $controls = new MenuControls($theme);
    if ($quantityMode) {
      $item = $mode->item;
      if ($item !== null) {
        $total = $mode->totalPrice . ' ' . $symbol;
        $arrowCells = (int)ceil($m->cellHeight / $m->cellWidth);
        $columns = [new MenuRowColumn(max(mb_strlen($total), strlen((string)$mode->maxQuantity))),
          new MenuRowColumn($arrowCells)];
        $rows = [new MenuRow('item', $item->name, [new MenuRowValue(''), new MenuRowValue('')],
          icon: $item instanceof Equipment ? CharacterMenuRows::getEquipmentIcon($item) : null),
          new MenuRow('quantity', Vocabulary::getTerm('shop.quantity', 'Quantity'), [new MenuRowValue((string)$mode->quantity), new MenuRowValue('')]),
          new MenuRow('total', Vocabulary::getTerm('shop.total', 'Total'), [new MenuRowValue($total), new MenuRowValue('')])];
        $y = $content->y;
        foreach ($rows as $row) {
          $rowHeight = $row->id === 'quantity' ? max($m->rowHeight, 2 * $m->cellHeight + $m->sectionGap) : $m->rowHeight;
          $layout = new MenuRowLayout(new CanvasRectangle($content->x, $y, $content->width,
            $content->y + $content->height - $y), $columns, $rowHeight, $m->cellWidth, $m->cellHeight, true);
          $view->rows('shop-checkout', [$row], $layout);
          if ($row->id === 'quantity') {
            $x = $content->x + $content->width - $theme->rows->metrics->padding - $arrowCells * $m->cellWidth;
            $top = $y + ($rowHeight - 2 * $m->cellHeight) / 2;
            $controls->renderChevron('shop-quantity-up', MenuDirection::UP->value,
              new CanvasRectangle($x, $top, $arrowCells * $m->cellWidth, $m->cellHeight), $mode->canIncreaseQuantity);
            $controls->renderChevron('shop-quantity-down', MenuDirection::DOWN->value,
              new CanvasRectangle($x, $top + $m->cellHeight, $arrowCells * $m->cellWidth, $m->cellHeight), $mode->canDecreaseQuantity);
          }
          $y += $layout->heightFor($row, $theme->rows->metrics, MenuRowPainter::canShowIcon($row, $theme->icons));
        }
      }
    } elseif (!$commandMode) {
      $rows = [];
      foreach ($state->mainPanel->items as $index => $item) {
        $price = $item->price * $state->mainPanel->priceRate . ' ' . $symbol;
        $selected = $state->mainPanel->activeItemIndex === $index;
        $rows[] = new MenuRow('item-' . $index, $item->name, [new MenuRowValue($price)],
          icon: $item instanceof Equipment ? CharacterMenuRows::getEquipmentIcon($item) : null,
          selected: $selected, focused: $selected);
      }
      $priceCells = max([1, ...array_map(static fn(MenuRow $row) => mb_strlen($row->values[0]->text), $rows)]);
      $view->rows('shop-items', $rows, self::layout($theme, $content, [new MenuRowColumn($priceCells)]),
        $state->mainPanel->activeItemIndex);
    }

    // The terminal detail window is blank at command selection, not a stale possession count.
    if (!$commandMode && ($quantityMode ? $mode->item : $state->mainPanel->activeItem) !== null) {
      $possession = (string)$state->detailPanel->possession;
      $view->rows('shop-details', [new MenuRow('possession', Vocabulary::getTerm('shop.possession', 'Possession'), [new MenuRowValue($possession)])],
        self::layout($theme, self::inset($detailBox, $p), [new MenuRowColumn(mb_strlen($possession))]));
    }
    if ($hintHeight > 0) {
      $view->hints('shop-hints', $hints, new CanvasRectangle($list->x,
        $list->y + $list->height - $hintHeight, $list->width, $hintHeight));
    }
    return MenuCanvas::overlay($view->finish(), $controls->finish($view->width, $view->height), $theme);
  }

  private static function inset(CanvasRectangle $box, int $padding): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $padding, $box->y + $padding,
      $box->width - 2 * $padding, $box->height - 2 * $padding);
  }

  private static function layout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $columns = []): MenuRowLayout
  {
    return new MenuRowLayout($box, $columns, $theme->metrics->rowHeight,
      $theme->metrics->cellWidth, $theme->metrics->cellHeight, true);
  }
}
