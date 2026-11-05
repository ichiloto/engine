<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;

use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\PurchaseConfirmationMode;
use Ichiloto\Engine\Core\Menu\ShopMenu\Modes\SelectShopMenuCommandMode;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

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
    $comparisonFocused = $state->detailPanel->comparisonFocused;
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
          selected: $selected, focused: $selected && !$comparisonFocused);
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
      $comparisonTop = $detailBox->y + $p + $m->rowHeight + $m->sectionGap;
      $detailContent = self::inset($detailBox, $p);
      $columns = self::layout($theme, $detailContent)->textCells($theme->rows->metrics);
      $comparisonWidth = $columns * $m->cellWidth;
      $comparisonX = $detailContent->x + $detailContent->width - $theme->rows->metrics->padding - $comparisonWidth;
      $partyProfiles = $state->detailPanel->getComparisonProfiles();
      $profiles = array_values(array_filter($partyProfiles,
        static fn(array $profile): bool => isset($theme->portraits[$profile['id']])
          && PngAssetPreflight::getAvailableSize($theme->assetRoot, $theme->portraits[$profile['id']]) !== null));
      $hasMarks = array_any($partyProfiles, static fn(array $profile): bool => $profile['mark'] !== null);
      $statLabel = $state->detailPanel->getComparisonStatLabel();
      if ($hasMarks && $statLabel !== null) {
        $labelHeight = count(MenuCanvas::wrap($statLabel, $columns)) * $m->cellHeight;
        $comparisonTop += $view->prose('shop-comparison-stat', $statLabel,
          new CanvasRectangle($comparisonX, $comparisonTop, $comparisonWidth, $labelHeight), 'muted');
      }
      if ($profiles !== []) {
        $size = max($m->cellWidth, min($m->portraitSize, 2 * $m->rowHeight, $comparisonWidth));
        $perRow = max(1, (int)floor(($comparisonWidth + $m->sectionGap) / ($size + $m->sectionGap)));
        $pitch = $size + ($hasMarks ? $m->cellHeight : 0) + $m->sectionGap;
        foreach ($profiles as $index => $profile) {
          $x = $comparisonX + ($index % $perRow) * ($size + $m->sectionGap);
          $y = $comparisonTop + intdiv($index, $perRow) * $pitch;
          $portrait = new CanvasRectangle($x, $y, $size, $size);
          $view->portrait($profile['id'], $portrait, 'shop-profile-' . $index, $profile['affected'] ? 1 : .3);
          if ($profile['id'] === $state->detailPanel->getCurrentComparisonActorId()) {
            $view->renderOutline('shop-profile-focus', $portrait, 'focus', $comparisonFocused ? 2 : 1, 24);
          }
          if ($profile['mark'] !== null) {
            $view->prose('shop-profile-mark-' . $index, $profile['mark']->value,
              new CanvasRectangle($x, $y + $size, $size, $m->cellHeight), $profile['mark']->getColorRole(),
              HorizontalAlignment::CENTER);
          }
        }
        $comparisonTop += (int)ceil(count($profiles) / $perRow) * $pitch;
      }
      $portraitIds = array_column($profiles, 'id');
      $summary = array_values(array_filter($partyProfiles, static fn(array $profile): bool =>
        $profile['mark'] !== null && !in_array($profile['id'], $portraitIds, true)));
      if ($summary !== []) {
        $cells = max(1, intdiv($columns, 2) - 1);
        foreach ($summary as $index => $profile) {
          $mark = $profile['mark'];
          $label = TerminalText::truncateToWidth($profile['name'], max(1, $cells - 2)) . ' ' . $mark->value;
          $view->prose('shop-profile-summary-' . $index, $label,
            new CanvasRectangle($comparisonX + ($index % 2) * intdiv($columns, 2) * $m->cellWidth,
              $comparisonTop + intdiv($index, 2) * $m->cellHeight, $cells * $m->cellWidth, $m->cellHeight),
            $mark->getColorRole());
        }
        $comparisonTop += (int)ceil(count($summary) / 2) * $m->cellHeight + $m->sectionGap;
      }
      $rows = max(1, (int)floor(($detailBox->y + $detailBox->height - $p - $comparisonTop) / $m->rowHeight));
      $showIdentity = !in_array($state->detailPanel->getCurrentComparisonActorId(), $portraitIds, true);
      $comparison = $state->detailPanel->getComparisonPage($columns, $rows, $showIdentity);
      if ($comparison !== null) {
        $hasNavigation = !$quantityMode && $state->detailPanel->canSelectComparisonActor();
        $comparisonHints = $hasNavigation ? $state->detailPanel->getComparisonNavigationHints() : [];
        $hintHeightInDetail = MenuActionHints::height($comparisonHints, $theme, $comparisonWidth, required: $hasNavigation);
        $more = $comparison['page']->total > max(1, $rows - (int)ceil($hintHeightInDetail / $m->rowHeight));
        if ($more) {
          $comparisonHints[] = ActionHints::resolve('info', 'More stats');
          $hintHeightInDetail = MenuActionHints::height($comparisonHints, $theme, $comparisonWidth, required: $hasNavigation);
        }
        $footerHeight = ($more ? $m->cellHeight : 0) + $hintHeightInDetail;
        if ($footerHeight > 0) {
          $comparison = $state->detailPanel->getComparisonPage($columns,
            max(1, $rows - (int)ceil($footerHeight / $m->rowHeight)), $showIdentity);
        }
        $texts = [];
        foreach ($comparison['lines'] as $row => $line) {
          foreach ($line['cells'] as $value) {
            if ($value['text'] === '') { continue; }
            $texts[] = ['text' => $value['text'], 'color' => $value['color'],
              'x' => $comparisonX + $value['x'] * $m->cellWidth,
              'y' => $comparisonTop + $row * $m->rowHeight + ($m->rowHeight - $m->cellHeight) / 2];
          }
        }
        $view->renderTexts('shop-comparison-content', $texts,
          new CanvasRectangle($comparisonX, $comparisonTop, $comparisonWidth, count($comparison['lines']) * $m->rowHeight),
          [$m->cellWidth, $m->cellHeight]);
        $footerY = $detailBox->y + $detailBox->height - $p - $footerHeight;
        if ($more) {
          $view->textLines('shop-comparison-range', [['text' => $comparison['page']->range(), 'color' => 'muted']],
            new CanvasRectangle($comparisonX, $footerY, $comparisonWidth, $m->cellHeight));
          $footerY += $m->cellHeight;
        }
        if ($hintHeightInDetail > 0) {
          $view->hints('shop-comparison-info', $comparisonHints,
            new CanvasRectangle($comparisonX, $footerY, $comparisonWidth, $hintHeightInDetail), required: $hasNavigation);
        }
      }
    }
    if ($hintHeight > 0 && !$comparisonFocused) {
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
