<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\DiscardItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectIemMenuCommandMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\SelectItemTargetMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\UseItemMode;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ViewKeyItemsMode;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use RuntimeException;

/** A view of the existing inventory controller, including its target and quantity phases. */
final class ItemMenuPresentation
{
  // Inventory names occupy the broad column; target/status share the remainder.
  private const int INVENTORY_WIDTH = 700;
  private const int TARGET_WIDTH = MenuLayout::MAX_WIDTH - self::INVENTORY_WIDTH;
  public static function compose(ItemMenuState $state, MenuPresentationCatalog $theme, float $time = 0): ?PresentationCanvas
  {
    if ($state->itemMenu === null || $state->selectionPanel === null) { return null; }
    $view = new MenuCanvas($theme, time: $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $mode = $state->mode;
    $info = $state->infoPanel?->text ?? '';
    $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('back', 'Back')];
    $hintHeight = MenuActionHints::height($hints, $theme, MenuLayout::MAX_WIDTH - 2 * $p);
    $infoHeight = MenuInfoPanel::getHeight($theme, $hints);
    $bodyBottom = MenuLayout::MAX_HEIGHT - $infoHeight;
    $commandHeight = max(60, $m->rowHeight + 2 * min($p, 10));
    $statusHeight = max(80, 2 * ($m->cellHeight + 2) + 2 * $p);
    if ($bodyBottom - $commandHeight - $statusHeight < 160) {
      throw new RuntimeException('Items description leaves insufficient space for inventory and targets.');
    }
    $view->frame('items-commands', self::box(0, 0, MenuLayout::MAX_WIDTH, $commandHeight), 'quiet');
    $commands = $state->itemMenu->getItems()->toArray();
    $width = (MenuLayout::MAX_WIDTH - 2 * $p - (count($commands) - 1) * $m->sectionGap) / max(1, count($commands));
    foreach ($commands as $index => $command) {
      $selected = $state->itemMenu->activeIndex === $index;
      $view->rows('items-commands', [new MenuRow('command-' . $index, $command->getLabel(), kind: MenuRowKind::BUTTON,
        selected: $selected, focused: $selected && $mode instanceof SelectIemMenuCommandMode,
        disabled: $command->isDisabled())], self::layout($theme,
          self::box($p + $index * ($width + $m->sectionGap), ($commandHeight - $m->rowHeight) / 2, $width, $m->rowHeight)));
    }

    $inventoryBox = self::box(0, $commandHeight, self::INVENTORY_WIDTH, $bodyBottom - $commandHeight);
    $view->frame('items-inventory', $inventoryBox);
    $rows = [];
    $itemFocus = $mode instanceof UseItemMode || $mode instanceof DiscardItemMode || $mode instanceof ViewKeyItemsMode;
    foreach ($state->selectionPanel->items as $index => $item) {
      $selected = $state->selectionPanel->activeIndex === $index;
      $rows[] = new MenuRow('item-' . $index, $item->name, [new MenuRowValue((string)$item->quantity)],
        selected: $selected, focused: $selected && $itemFocus);
    }
    $quantityCells = max([2, ...array_map(fn(MenuRow $row) => mb_strlen($row->values[0]->text), $rows)]);
    $view->rows('items-inventory', $rows, self::layout($theme, self::inset($inventoryBox, $p),
      [new MenuRowColumn($quantityCells)]), $state->selectionPanel->activeIndex);

    $targetBox = self::box(self::INVENTORY_WIDTH, $commandHeight, self::TARGET_WIDTH, $bodyBottom - $commandHeight - $statusHeight);
    $view->frame('items-targets', $targetBox);
    $rows = [];
    foreach ($state->targetSelectionPanel?->targets ?? [] as $index => $target) {
      $selected = $state->targetSelectionPanel->activeIndex === $index;
      $rows[] = new MenuRow('target-' . $index, $target->name, selected: $selected,
        focused: $selected && $mode instanceof SelectItemTargetMode);
    }
    $view->rows('items-targets', $rows, self::layout($theme, self::inset($targetBox, $p)),
      $state->targetSelectionPanel?->activeIndex ?? -1);

    $statusBox = self::box(self::INVENTORY_WIDTH, $bodyBottom - $statusHeight, self::TARGET_WIDTH, $statusHeight);
    $view->frame('items-status', $statusBox, 'quiet');
    $target = $state->targetSelectionPanel?->activeCharacter;
    if ($target !== null) {
      $hp = $target->stats->currentHp . ' / ' . $target->stats->totalHp;
      $mp = $target->stats->currentMp . ' / ' . $target->stats->totalMp;
      $columns = [new MenuRowColumn(max(mb_strlen($hp), mb_strlen($mp)))];
      $view->rows('items-status', [new MenuRow('hp', sprintf('Lvl %02d HP', $target->level), [new MenuRowValue($hp)]),
        new MenuRow('mp', 'MP', [new MenuRowValue($mp)])], new MenuRowLayout(self::inset($statusBox, $p), $columns,
          $m->cellHeight + 2, $m->cellWidth, $m->cellHeight, true));
    }

    $infoBox = self::box(0, $bodyBottom, MenuLayout::MAX_WIDTH, $infoHeight);
    $view->frame('items-info', $infoBox, 'quiet');
    MenuInfoPanel::renderContent($view, 'items', self::inset($infoBox, $p), $info,
      infoModel: $state->menuInfoText, rangeBounds: new CanvasRectangle($infoBox->x + $p,
        $infoBox->y + $infoBox->height - $p, $infoBox->width - 2 * $p, $p));
    if ($hintHeight > 0) {
      $view->hints('items-hints', $hints, self::box($p, MenuLayout::MAX_HEIGHT - $p - $hintHeight, MenuLayout::MAX_WIDTH - 2 * $p, $hintHeight));
    }
    return $view->finish();
  }

  private static function box(float $x, float $y, float $w, float $h): CanvasRectangle
  {
    $host = MenuLayout::getBounds();
    return new CanvasRectangle($host->x + $x, $host->y + $y, $w, $h);
  }

  private static function inset(CanvasRectangle $box, int $padding): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $padding, $box->y + $padding, $box->width - 2 * $padding, $box->height - 2 * $padding);
  }

  private static function layout(MenuPresentationCatalog $theme, CanvasRectangle $box, array $columns = []): MenuRowLayout
  {
    return new MenuRowLayout($box, $columns, $theme->metrics->rowHeight, $theme->metrics->cellWidth, $theme->metrics->cellHeight, true);
  }
}
