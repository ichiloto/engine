<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** Save records remain owned by SaveManager; this view never reads or writes saves. */
final class SaveLoadMenuPresentation
{
  // Leader details take the broad column; elapsed time remains right-aligned beside them.
  private const float DETAIL_WIDTH_SHARE = 0.7;
  /** @param list<SaveSlot> $slots */
  public static function compose(array $slots, int $activeIndex, MenuPresentationCatalog $theme,
    MenuInfoText $info, ?string $status = null, float $time = 0, string $title = 'Continue',
    string $prompt = 'Choose a save file to continue from.', string $statusColor = 'decrease'): PresentationCanvas
  {
    $view = new MenuCanvas($theme, time: $time);
    $box = MenuLayout::getBounds();
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = max(1, $m->sectionGap / 2);
    $headerHeight = max(88, 2 * $p + 2 * $m->cellHeight);
    $footerHeight = $headerHeight;
    $list = new CanvasRectangle($box->x, $box->y + $headerHeight, $box->width,
      $box->height - $headerHeight - $footerHeight);
    $header = new CanvasRectangle($box->x, $box->y, $box->width, $headerHeight);
    $view->frame('save-prompt', $header, 'quiet');
    $view->prose('save-title', $title, new CanvasRectangle($box->x + $p, $box->y + $p,
      $box->width - 2 * $p, $m->cellHeight), 'accent');
    $view->prose('save-prompt-text', $prompt, new CanvasRectangle($box->x + $p, $box->y + $p + $m->cellHeight,
      $box->width - 2 * $p, $m->cellHeight));
    $heights = [];
    $left = $box->x + $p + $theme->rows->metrics->padding;
    $textWidth = $box->width - 2 * ($p + $theme->rows->metrics->padding);
    foreach ($slots as $slot) {
      $locationHeight = count(MenuCanvas::wrap($slot->isEmpty ? 'Empty File' : $slot->locationName,
        (int)floor($textWidth / $m->cellWidth))) * $m->cellHeight;
      $details = self::getDetailText($slot);
      $detailWidth = $slot->isLoadable ? $textWidth * self::DETAIL_WIDTH_SHARE : $textWidth;
      $detailHeight = $details === '' ? 0 : count(MenuCanvas::wrap($details, (int)floor($detailWidth / $m->cellWidth))) * $m->cellHeight;
      $heights[] = max(100, $m->cellHeight + $locationHeight + $detailHeight + $p) + $gap;
    }
    if ($heights !== []) { $heights[array_key_last($heights)] -= $gap; }
    [$first, $last] = $slots === [] ? [0, -1] : $view->visibleRange('save-slots', $heights, $list, $activeIndex,
      new CanvasRectangle($box->x + $box->width / 2, $box->y + $p, $box->width / 2 - $p, $m->cellHeight));
    $y = $list->y;
    for ($index = $first; $index <= $last; $index++) {
      $slot = $slots[$index];
      $id = 'save-slot-' . $slot->slot;
      $card = new CanvasRectangle($list->x, $y, $list->width,
        $heights[$index] - ($index === array_key_last($slots) ? 0 : $gap));
      $view->frame($id . '-frame', $card, borderLayer: 20);
      $edges = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot, $card) ?? [4, 4, 4, 4];
      $treatment = new CanvasRectangle($card->x + $edges[0], $card->y + $edges[1],
        $card->width - $edges[0] - $edges[2], $card->height - $edges[1] - $edges[3]);
      $view->prose($id . '-title', 'File ' . $slot->slot,
        new CanvasRectangle($card->x + $p, $card->y + 4, $card->width - 2 * $p, $m->cellHeight), 'accent');
      $identity = new MenuRow('location', $slot->isEmpty ? 'Empty File' : $slot->locationName,
        selected: $index === $activeIndex, focused: $index === $activeIndex);
      $identityBox = new CanvasRectangle($card->x + $p, $y + $m->cellHeight + 4,
        $card->width - 2 * $p, $card->height - $m->cellHeight - 4);
      $layout = new MenuRowLayout($identityBox, rowHeight: (int)ceil(max($m->cellHeight + 1, $theme->rows->metrics->cursorHeight)),
        cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
      $view->record($id, $identity, $layout, $treatment);
      $detailY = $identityBox->y + $layout->heightFor($identity, $theme->rows->metrics, false);
      if (!$slot->isEmpty) {
        $view->prose($id . '-detail', self::getDetailText($slot),
          new CanvasRectangle($left, $detailY, $slot->isLoadable ? $textWidth * self::DETAIL_WIDTH_SHARE : $textWidth,
            $card->y + $card->height - $detailY - 4), $slot->isLoadable ? 'text' : 'decrease');
        if ($slot->isLoadable) {
          $seconds = max(0, $slot->playTimeSeconds);
          $duration = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
          $view->prose($id . '-duration', $duration, new CanvasRectangle($left + $textWidth * self::DETAIL_WIDTH_SHARE, $detailY,
            $textWidth * (1 - self::DETAIL_WIDTH_SHARE), $m->cellHeight), alignment: HorizontalAlignment::RIGHT);
        }
      }
      $y += $heights[$index];
    }
    $footer = new CanvasRectangle($box->x, $box->y + $box->height - $footerHeight, $box->width, $footerHeight);
    $view->frame('save-info', $footer, 'quiet');
    MenuInfoPanel::renderContent($view, 'save-info', new CanvasRectangle($footer->x + $p, $footer->y + $p,
      $footer->width - 2 * $p, 2 * $m->cellHeight), $status === null ? 'Choose a file.' : '', $status,
      $statusColor, $info,
      new CanvasRectangle($footer->x + $footer->width / 2, $footer->y, $footer->width / 2 - $p, $m->cellHeight));
    return $view->finish();
  }

  private static function getDetailText(SaveSlot $slot): string
  {
    return $slot->isEmpty ? '' : ($slot->isLoadable ? $slot->getLeaderSummary() : ($slot->statusMessage ?? 'Cannot be loaded.'));
  }
}
