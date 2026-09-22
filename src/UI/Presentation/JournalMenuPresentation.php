<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/** Same-panel quest journal and Records Info. All navigation remains with the states. */
final class JournalMenuPresentation
{
  /** @return array{int, int} Text columns and rows, shared with the PHP scrolling owner. */
  public static function pageSize(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width = 1350, int $height = 720): array
  {
    if ($content->journal && $content->document !== null) { return QuestJournalPresentation::pageSize($content, $theme, $width, $height); }
    $bounds = self::geometry($content, $theme, $width, $height)['text'];
    return [(int)floor($bounds->width / $theme->metrics->cellWidth), (int)floor($bounds->height / $theme->metrics->cellHeight)];
  }

  public static function compose(JournalMenuContent $content, MenuPresentationCatalog $theme, TextPage $page,
    float $time = 0, int $width = 1350, int $height = 720): PresentationCanvas
  {
    if ($content->journal && $content->document !== null) { return QuestJournalPresentation::compose($content, $theme, $page, $time, $width, $height); }
    $g = self::geometry($content, $theme, $width, $height);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $view = new MenuCanvas($theme, $width, $height, $time);
    foreach (['summary', 'list', 'info'] as $id) {
      $view->frame('journal-' . $id, $g[$id], $id === 'info' ? 'quiet' : 'panel');
    }
    $view->prose('journal-title', $content->title . ($content->summary !== '' ? "\n" . $content->summary : ''), $g['title'], 'accent');
    foreach ($content->tabs as $i => $label) {
      $view->rows('journal-tab', [new MenuRow((string)$i, $label, kind: MenuRowKind::BUTTON, selected: $i === $content->tabIndex)],
        new MenuRowLayout($g['tabs'][$i], rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
    }
    if (!$content->journal || !$content->detailsOpen) {
      $bounds = self::inset($g['list'], $p);
      if ($content->rows === []) { $view->prose('journal-empty', $content->emptyText, $bounds, 'disabled'); }
      else {
        $cells = (int)floor(($bounds->width - 2 * $theme->rows->metrics->padding) / $m->cellWidth);
        $valueCount = max(array_map(fn($row) => count($row['values']), $content->rows));
        $valueCells = max(4, (int)floor($cells * 0.50 / max(1, $valueCount)) - $theme->rows->metrics->gapCells);
        $labelCells = $cells - $valueCount * ($valueCells + $theme->rows->metrics->gapCells);
        $rows = [];
        foreach ($content->rows as $i => $row) {
          $rows[] = new MenuRow((string)$i, TextViewport::preview($row['label'], $labelCells),
            array_map(fn($value) => new MenuRowValue(TextViewport::preview($value, $valueCells)), $row['values']),
            selected: $i === $content->index, focused: $i === $content->index && !$content->detailsOpen);
        }
        $columns = [];
        for ($i = 0; $i < $valueCount; $i++) {
          $columns[] = new MenuRowColumn($valueCells, $content->journal && $i === 1 ? HorizontalAlignment::RIGHT : HorizontalAlignment::LEFT);
        }
        $view->rows('journal-entry', $rows, new MenuRowLayout($bounds, $columns, $m->rowHeight, $m->cellWidth, $m->cellHeight), $content->index);
      }
    }
    $hasEntry = $content->rows !== [];
    $metadata = !$hasEntry ? '' : ($content->detailsOpen ? 'Details - ' . $page->range() : 'View details');
    $view->prose('journal-reading-state', $metadata, $g['metadata'], $content->detailsOpen ? 'accent' : 'disabled');
    if ($hasEntry && (!$content->journal || $content->detailsOpen)) {
      if ($page->columns !== (int)floor($g['text']->width / $m->cellWidth)
        || $page->rows !== (int)floor($g['text']->height / $m->cellHeight)) {
        throw new RuntimeException('Journal text page must match the measured display viewport.');
      }
      $view->prose('journal-text', implode("\n", $page->lines), $g['text']);
    } elseif ($hasEntry) {
      $view->prose('journal-preview', TextViewport::preview($content->previewText, (int)floor($g['preview']->width / $m->cellWidth)), $g['preview']);
    }
    if ($g['hints'] !== null) { $view->hints('journal-hints', self::hints($content), $g['hints']); }
    return $view->finish();
  }

  private static function hints(JournalMenuContent $content): array
  {
    $hints = [ActionHints::resolve('cancel', $content->detailsOpen ? 'Back' : 'Cancel')];
    if (!$content->detailsOpen) { array_unshift($hints, ActionHints::resolve('confirm', 'View details')); }
    if (!$content->journal || !$content->detailsOpen) {
      $hints[] = ActionHints::resolve('character_next', 'Tab', KeyCode::TAB);
      $hints[] = ActionHints::resolve('character_previous', 'Previous tab', KeyCode::SHIFT_TAB);
    }
    return $hints;
  }

  private static function geometry(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width, int $height): array
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $host = new CanvasRectangle(($width - min(1100, $width - 20)) / 2, ($height - min(700, $height - 20)) / 2,
      min(1100, $width - 20), min(700, $height - 20));
    $inner = $host->width - 2 * $p;
    $titleWidth = floor($inner * 0.22);
    $tabWidth = ($inner - $titleWidth - count($content->tabs) * $gap) / count($content->tabs);
    $titleText = $content->title . ($content->summary !== '' ? "\n" . $content->summary : '');
    $summaryHeight = max(80, 2 * $p + count(MenuCanvas::wrap($titleText, (int)floor($titleWidth / $m->cellWidth))) * $m->cellHeight);
    $tabLayout = new MenuRowLayout(new CanvasRectangle(0, 0, $tabWidth, 1), rowHeight: $m->rowHeight,
      cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
    foreach ($content->tabs as $i => $label) {
      $summaryHeight = max($summaryHeight, 2 * $p + $tabLayout->heightFor(new MenuRow((string)$i, $label, kind: MenuRowKind::BUTTON), $theme->rows->metrics, false));
    }
    $hintHeight = MenuActionHints::height(self::hints($content), $theme, $inner);
    $hintSpace = $hintHeight > 0 ? $hintHeight + $gap : 0;
    $infoHeight = max($content->journal ? 80 : 160, 2 * $p + $m->cellHeight * ($content->journal ? 2 : 3) + $gap + $hintSpace);
    $listHeight = $host->height - $summaryHeight - $infoHeight;
    if ($listHeight < 2 * $p + $m->rowHeight + $m->cellHeight) {
      throw new RuntimeException('Journal theme leaves no readable list and text viewport; terminal presentation retained.');
    }
    $summary = new CanvasRectangle($host->x, $host->y, $host->width, $summaryHeight);
    $list = new CanvasRectangle($host->x, $host->y + $summaryHeight, $host->width, $listHeight);
    $info = new CanvasRectangle($host->x, $list->y + $listHeight, $host->width, $infoHeight);
    $x = $host->x + $p;
    $metadata = new CanvasRectangle($x, $info->y + $p, $inner, $m->cellHeight);
    $text = $content->journal ? self::inset($list, $p)
      : new CanvasRectangle($x, $metadata->y + $m->cellHeight + $gap, $inner, $infoHeight - 2 * $p - $m->cellHeight - $gap - $hintSpace);
    $tabs = [];
    foreach ($content->tabs as $i => $_) {
      $tabs[] = new CanvasRectangle($x + $titleWidth + $gap + $i * ($tabWidth + $gap), $summary->y + $p, $tabWidth, $summaryHeight - 2 * $p);
    }
    return ['summary' => $summary, 'list' => $list, 'info' => $info, 'text' => $text, 'metadata' => $metadata, 'tabs' => $tabs,
      'title' => new CanvasRectangle($x, $summary->y + $p, $titleWidth, $summaryHeight - 2 * $p),
      'preview' => new CanvasRectangle($x, $metadata->y + $m->cellHeight, $inner, $m->cellHeight),
      'hints' => $hintHeight > 0 ? new CanvasRectangle($x, $info->y + $infoHeight - $p - $hintHeight, $inner, $hintHeight) : null];
  }

  private static function inset(CanvasRectangle $bounds, int $padding): CanvasRectangle
  {
    return new CanvasRectangle($bounds->x + $padding, $bounds->y + $padding, $bounds->width - 2 * $padding, $bounds->height - 2 * $padding);
  }
}
