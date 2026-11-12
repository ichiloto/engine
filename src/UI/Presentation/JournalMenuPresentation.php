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
  /** The reading viewport, shared with the PHP scrolling owner. */
  public static function pageSize(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): MenuDetailViewport
  {
    if ($content->journal && $content->document !== null) { return QuestJournalPresentation::pageSize($content, $theme, $width, $height); }
    $bounds = self::geometry($content, $theme, $width, $height)['text'];
    return new MenuDetailViewport((int)floor($bounds->width / $theme->metrics->cellWidth), (int)floor($bounds->height),
      $theme->metrics->cellHeight);
  }

  public static function compose(JournalMenuContent $content, MenuPresentationCatalog $theme, TextPage $page,
    float $time = 0, int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    if ($content->journal && $content->document !== null) { return QuestJournalPresentation::compose($content, $theme, $page, $time, $width, $height); }
    $g = self::geometry($content, $theme, $width, $height);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $view = new MenuCanvas($theme, $width, $height, $time);
    foreach (['summary', 'list', 'info'] as $id) {
      $view->frame('journal-' . $id, $g[$id], $id === 'info' ? 'quiet' : 'panel');
    }
    $view->prose('journal-title', $content->title, $g['title'], 'accent');
    if ($content->summary !== '') { $view->prose('journal-summary-value', $content->summary, $g['summaryText'], 'accent', HorizontalAlignment::RIGHT); }
    foreach ($content->tabs as $i => $label) {
      $view->rows('journal-tab', [new MenuRow((string)$i, $label, kind: MenuRowKind::BUTTON, selected: $i === $content->tabIndex)],
        new MenuRowLayout($g['tabs'][$i], rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
    }
    if (!$content->journal || !$content->detailsOpen) {
      $bounds = self::inset($g['list'], $p);
      if ($content->rows === []) { $view->prose('journal-empty', $content->emptyText, $bounds, 'disabled'); }
      else {
        $rows = [];
        foreach ($content->rows as $i => $row) {
          $rows[] = new MenuRow((string)$i, $row['label'],
            array_map(fn($value) => new MenuRowValue(TextViewport::preview($value, max(1, (int)floor($bounds->width / $m->cellWidth)))), $row['values']),
            selected: $i === $content->index, focused: $i === $content->index && !$content->detailsOpen);
        }
        $heading = self::createHeading($content);
        $layout = MenuRowLayout::measureTable($bounds, $rows, $theme->rows->metrics,
          $m->rowHeight, $m->cellWidth, $m->cellHeight, $heading);
        $rows = array_map(static fn(MenuRow $row): MenuRow => new MenuRow($row->id, $row->label,
          array_map(static fn(MenuRowValue $value, int $index): MenuRowValue =>
            new MenuRowValue(TextViewport::preview($value->text, $layout->columns[$index]->cells)), $row->values, array_keys($row->values)),
          selected: $row->selected, focused: $row->focused), $rows);
        if ($heading !== null) {
          $headingHeight = $layout->heightFor($heading, $theme->rows->metrics, false);
          $view->rows('journal-columns', [$heading], new MenuRowLayout(
            new CanvasRectangle($bounds->x, $bounds->y, $bounds->width, $headingHeight), $layout->columns,
            $m->rowHeight, $m->cellWidth, $m->cellHeight, wrapText: true));
          $bounds = new CanvasRectangle($bounds->x, $bounds->y + $headingHeight, $bounds->width, $bounds->height - $headingHeight);
          $layout = new MenuRowLayout($bounds, $layout->columns, $m->rowHeight, $m->cellWidth, $m->cellHeight, wrapText: true);
        }
        $view->rows('journal-entry', $rows, $layout, $content->index);
      }
    }
    $hasEntry = $content->rows !== [];
    $metadata = !$hasEntry ? '' : ($content->detailsOpen ? 'Details - ' . $page->range() : ($content->confirmLabel ?? ''));
    $view->prose('journal-reading-state', $metadata, $g['metadata'], $content->detailsOpen ? 'accent' : 'disabled');
    if ($hasEntry && (!$content->journal || $content->detailsOpen)) {
      $area = new MenuDetailViewport((int)floor($g['text']->width / $m->cellWidth), (int)floor($g['text']->height), $m->cellHeight);
      $details = $content->details->layout($area->columns, $theme->createDetailStyle(), $area);
      if ($page->columns !== $area->columns || $page->rows !== $details->getPageHeight($area)) {
        throw new RuntimeException('Journal text page must match the measured display viewport.');
      }
      $view->details('journal-text', $details, $page, $g['text']);
    } elseif ($hasEntry) {
      $view->prose('journal-preview', TextViewport::preview($content->previewText, (int)floor($g['preview']->width / $m->cellWidth)), $g['preview']);
    }
    if ($g['hints'] !== null) { $view->hints('journal-hints', self::hints($content), $g['hints']); }
    return $view->finish();
  }

  public static function createHeading(JournalMenuContent $content): ?MenuRow
  {
    if ($content->columnHeadings === []) { return null; }
    return new MenuRow('column-headings', $content->columnHeadings[0],
      array_map(static fn(string $text): MenuRowValue => new MenuRowValue($text), array_slice($content->columnHeadings, 1)),
      kind: MenuRowKind::HEADING, showCursor: false);
  }

  private static function hints(JournalMenuContent $content): array
  {
    $hints = [ActionHints::resolve('cancel', $content->detailsOpen ? 'Back' : 'Cancel')];
    if (!$content->detailsOpen && $content->confirmLabel !== null) {
      array_unshift($hints, ActionHints::resolve('confirm', $content->confirmLabel));
    }
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
    $host = MenuLayout::getBounds($width, $height);
    $inner = $host->width - 2 * $p;
    $titleWidth = min(floor($inner * 0.15), max(8, mb_strlen($content->title, 'UTF-8')) * $m->cellWidth);
    $summaryWidth = min(floor($inner * 0.2), max(12, mb_strlen($content->summary, 'UTF-8')) * $m->cellWidth);
    $tabWidth = ($inner - $titleWidth - $summaryWidth - (count($content->tabs) + 1) * $gap) / count($content->tabs);
    $summaryHeight = max(80, 2 * $p + max(count(MenuCanvas::wrap($content->title, (int)floor($titleWidth / $m->cellWidth))),
      count(MenuCanvas::wrap($content->summary, (int)floor($summaryWidth / $m->cellWidth)))) * $m->cellHeight);
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
      'summaryText' => new CanvasRectangle($x + $inner - $summaryWidth, $summary->y + $p, $summaryWidth, $summaryHeight - 2 * $p),
      'preview' => new CanvasRectangle($x, $metadata->y + $m->cellHeight, $inner, $m->cellHeight),
      'hints' => $hintHeight > 0 ? new CanvasRectangle($x, $info->y + $infoHeight - $p - $hintHeight, $inner, $hintHeight) : null];
  }

  private static function inset(CanvasRectangle $bounds, int $padding): CanvasRectangle
  {
    return new CanvasRectangle($bounds->x + $padding, $bounds->y + $padding, $bounds->width - 2 * $padding, $bounds->height - 2 * $padding);
  }
}
