<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\UI\Windows\SaveSlotPage;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/** Save records remain owned by SaveManager; this view never reads or writes saves. */
final class SaveLoadMenuPresentation
{
  // Leader details take the broad column; elapsed time remains right-aligned beside them.
  private const float DETAIL_WIDTH_SHARE = 0.7;
  /** @param list<SaveSlot> $slots */
  public static function compose(array $slots, int $activeIndex, MenuPresentationCatalog $theme,
    MenuInfoText $info, ?string $status = null, float $time = 0, ?string $title = null,
    ?string $prompt = null, string $statusColor = 'decrease'): PresentationCanvas
  {
    $title ??= Vocabulary::getTerm('game.load', Vocabulary::getTerm('game.continue', Vocabulary::getTerm('command.continue', 'Continue')));
    $prompt ??= get_message('prompt.load', 'Choose a save file to continue from.');
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
    $left = $box->x + $p + $theme->rows->metrics->padding;
    $textWidth = $box->width - 2 * ($p + $theme->rows->metrics->padding);
    // Three metadata lines must fit wholly; unusually large theme metrics use
    // a smaller page explicitly rather than spilling outside the viewport.
    $minimumCardHeight = 3 * $m->cellHeight + 8;
    $capacity = min(SaveSlotPage::SIZE, max(1, (int)floor(($list->height + $gap) / ($minimumCardHeight + $gap))));
    if ($list->height < $minimumCardHeight) {
      throw new \InvalidArgumentException('Save viewport cannot fit one complete save record.');
    }
    $cardHeight = ($list->height - ($capacity - 1) * $gap) / $capacity;
    [$first, $end] = SaveSlotPage::getVisibleRange(count($slots), $activeIndex, $capacity);
    $last = $end - 1;
    $view->prose('save-slots-range', sprintf('%d-%d / %d', $slots === [] ? 0 : $first + 1, $end, count($slots)),
      new CanvasRectangle($box->x + $box->width / 2, $box->y + $p, $box->width / 2 - $p, $m->cellHeight),
      alignment: HorizontalAlignment::RIGHT);
    $y = $list->y;
    for ($index = $first; $index <= $last; $index++) {
      $slot = $slots[$index];
      $id = 'save-slot-' . $slot->slot;
      // Automatic checkpoints share a slot number; the selected file owns identity.
      if ($slot->slot <= 0) { $id .= '-' . hash('sha256', $slot->path); }
      $card = new CanvasRectangle($list->x, $y, $list->width,
        $cardHeight);
      $view->frame($id . '-frame', $card, borderLayer: 20);
      $edges = ($theme->frames['panel'] ?? null)?->borderInsets($theme->assetRoot, $card) ?? [4, 4, 4, 4];
      $treatment = new CanvasRectangle($card->x + $edges[0], $card->y + $edges[1],
        $card->width - $edges[0] - $edges[2], $card->height - $edges[1] - $edges[3]);
      $view->prose($id . '-title', $slot->getLabel(),
        new CanvasRectangle($card->x + $p, $card->y + 4, $card->width - 2 * $p, $m->cellHeight), 'accent');
      $identity = new MenuRow('location', TerminalText::truncateToWidth($slot->isEmpty ? 'Empty File' : $slot->locationName,
        max(1, (int)floor(($card->width - 2 * $p - 2 * $theme->rows->metrics->padding - $theme->rows->metrics->cursorHeight) / $m->cellWidth))),
        selected: $index === $activeIndex, focused: $index === $activeIndex);
      $identityBox = new CanvasRectangle($card->x + $p, $y + $m->cellHeight + 4,
        $card->width - 2 * $p, $card->height - $m->cellHeight - 4);
      $layout = new MenuRowLayout($identityBox, rowHeight: (int)ceil(max($m->cellHeight + 1, $theme->rows->metrics->cursorHeight)),
        cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: false);
      $view->record($id, $identity, $layout, $treatment);
      $detailY = $identityBox->y + $layout->heightFor($identity, $theme->rows->metrics, false);
      if (!$slot->isEmpty) {
        $view->prose($id . '-detail', TerminalText::truncateToWidth(self::getDetailText($slot),
          max(1, (int)floor(($slot->isLoadable ? $textWidth * self::DETAIL_WIDTH_SHARE : $textWidth) / $m->cellWidth))),
          new CanvasRectangle($left, $detailY, $slot->isLoadable ? $textWidth * self::DETAIL_WIDTH_SHARE : $textWidth,
            $card->y + $card->height - $detailY - 4), $slot->isLoadable ? 'text' : 'decrease');
        if ($slot->isLoadable) {
          $seconds = max(0, $slot->playTimeSeconds);
          $duration = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
          $view->prose($id . '-duration', $duration, new CanvasRectangle($left + $textWidth * self::DETAIL_WIDTH_SHARE, $detailY,
            $textWidth * (1 - self::DETAIL_WIDTH_SHARE), $m->cellHeight), alignment: HorizontalAlignment::RIGHT);
        }
      }
      $y += $cardHeight + $gap;
    }
    $footer = new CanvasRectangle($box->x, $box->y + $box->height - $footerHeight, $box->width, $footerHeight);
    $view->frame('save-info', $footer, 'quiet');
    MenuInfoPanel::renderContent($view, 'save-info', new CanvasRectangle($footer->x + $p, $footer->y + $p,
      $footer->width - 2 * $p, 2 * $m->cellHeight), $status === null ? ($slots[$activeIndex] ?? null)?->getDescription() ?? 'Choose a file.' : '', $status,
      $statusColor, $info,
      new CanvasRectangle($footer->x + $footer->width / 2, $footer->y, $footer->width / 2 - $p, $m->cellHeight));
    return $view->finish();
  }

  private static function getDetailText(SaveSlot $slot): string
  {
    return $slot->isEmpty ? '' : ($slot->isLoadable ? $slot->getLeaderSummary() : ($slot->statusMessage ?? 'Cannot be loaded.'));
  }
}
