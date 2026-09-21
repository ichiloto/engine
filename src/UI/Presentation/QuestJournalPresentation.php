<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use RuntimeException;

/** Persistent quest navigation beside a sectioned, independently scrollable journal. */
final class QuestJournalPresentation
{
  /** @return array{int, int} */
  public static function pageSize(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width = 1350, int $height = 720): array
  {
    $text = self::geometry($content, $theme, $width, $height)['text'];
    return [(int)floor($text->width / $theme->metrics->cellWidth), (int)floor($text->height / $theme->metrics->cellHeight)];
  }

  public static function compose(JournalMenuContent $content, MenuPresentationCatalog $theme, TextPage $page,
    float $time = 0, int $width = 1350, int $height = 720): PresentationCanvas
  {
    $g = self::geometry($content, $theme, $width, $height);
    [$columns, $rows] = self::pageSize($content, $theme, $width, $height);
    if ($page->columns !== $columns || $page->rows !== $rows || $page->source !== $content->document?->text) {
      throw new RuntimeException('Journal text page must match the measured document viewport.');
    }
    $view = new MenuCanvas($theme, $width, $height, $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    foreach (['header', 'list', 'detail'] as $role) {
      $view->backing('journal-' . $role . '-backing', $g[$role]);
      $view->frame('journal-' . $role, $g[$role], $role === 'header' ? 'quiet' : 'panel');
    }
    if ($content->detailsOpen) {
      $view->surface('journal-reading-focus', new CanvasRectangle($g['detail']->x + 8, $g['detail']->y + 12,
        2, $g['detail']->height - 24), 'focus', 22);
    }
    $iconWidth = $g['gutter'];
    $view->icon('journal-heading-icon', 'journal.quests', new CanvasRectangle($g['header']->x + $p,
      $g['header']->y + $p, $iconWidth - 4, $m->rowHeight));
    $titleWidth = $g['list']->width - 2 * $p - $iconWidth;
    $view->prose('journal-title', $content->title, new CanvasRectangle($g['header']->x + $p + $iconWidth,
      $g['header']->y + ($g['header']->height - $m->cellHeight) / 2, $titleWidth, $m->cellHeight), 'accent');
    $tabWidth = ($g['detail']->width - 2 * $p) / max(1, count($content->tabs));
    foreach ($content->tabs as $i => $tab) {
      $view->rows('journal-tab', [new MenuRow((string)$i, $tab, kind: MenuRowKind::BUTTON,
        selected: $i === $content->tabIndex)], new MenuRowLayout(new CanvasRectangle($g['detail']->x + $p + $tabWidth * $i,
          $g['header']->y + $p, $tabWidth, $g['header']->height - 2 * $p), rowHeight: $m->rowHeight,
          cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
    }
    $list = self::inset($g['list'], $p);
    if ($content->rows === []) {
      $view->prose('journal-empty', $content->emptyText, $list, 'disabled');
      return $view->finish();
    }
    $rowHeight = $m->rowHeight + $m->cellHeight;
    [$first, $last] = $view->visibleRange('journal-entry', array_fill(0, count($content->rows), $rowHeight), $list, $content->index);
    foreach (array_slice($content->rows, $first, $last - $first + 1, true) as $index => $row) {
      $record = new CanvasRectangle($list->x, $list->y + ($index - $first) * $rowHeight, $list->width, $rowHeight);
      $layout = new MenuRowLayout(new CanvasRectangle($record->x, $record->y, $record->width, $m->rowHeight),
        rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight);
      $icon = isset($theme->icons?->icons[$row['icon'] ?? '']) ? $row['icon'] : null;
      $iconCells = $icon !== null ? (int)ceil($theme->rows->metrics->iconWidth / $m->cellWidth) + $theme->rows->metrics->gapCells : 0;
      $cells = $layout->textCells($theme->rows->metrics) - $iconCells;
      $view->record('journal-entry-' . $index, new MenuRow((string)$index, TextViewport::preview($row['label'], $cells), icon: $icon,
        selected: $index === $content->index, focused: $index === $content->index && !$content->detailsOpen), $layout, $record);
      $view->prose('journal-entry-' . $index . '-progress', TextViewport::preview($row['values'][1] ?? '', $cells),
        new CanvasRectangle($record->x + $theme->rows->metrics->padding + $iconCells * $m->cellWidth,
          $record->y + $m->rowHeight - 2, $cells * $m->cellWidth, $m->cellHeight), 'disabled');
    }
    self::document($view, $content->document, $page, $g);
    if ($page->total > $page->rows) {
      $track = $g['scroll'];
      $view->surface('journal-scroll-track', $track, 'edge');
      $thumbHeight = max(16, $track->height * $page->rows / $page->total);
      $offset = ($track->height - $thumbHeight) * $page->first / ($page->total - $page->rows);
      $view->surface('journal-scroll-thumb', new CanvasRectangle($track->x, $track->y + $offset,
        $track->width, $thumbHeight), $content->detailsOpen ? 'focus' : 'accent', 21);
    }
    if ($theme->showInputHints) {
      $view->hints('journal-hints', [ActionHints::resolve('confirm', 'Read'), ActionHints::resolve('cancel', 'Back')], $g['hints']);
    }
    return $view->finish();
  }

  private static function document(MenuCanvas $view, JournalDocument $document, TextPage $page, array $g): void
  {
    $m = $view->theme->metrics;
    $styles = array_slice($document->lines($page->columns), $page->first, $page->rows);
    $sections = [];
    foreach ($styles as $index => $line) {
      if ($line['section'] !== null) { $sections[$line['section']][] = $index; }
    }
    // Each visible section has its own bounded surface. Scrolling never puts text under a frame edge.
    foreach ($sections as $section => $indexes) {
      $top = $g['text']->y + min($indexes) * $m->cellHeight;
      $bottom = $g['text']->y + (max($indexes) + 1) * $m->cellHeight;
      $view->surface('journal-section-' . $section, new CanvasRectangle($g['sectionX'], $top,
        $g['sectionWidth'], $bottom - $top), 'background', 18);
    }
    $markers = [];
    foreach ($styles as $index => $line) {
      $y = $g['text']->y + $index * $m->cellHeight;
      $fallback = '';
      if ($line['heading']) {
        $view->surface('journal-section-heading-' . $index, new CanvasRectangle($g['sectionX'], $y,
          $g['sectionWidth'], $m->cellHeight), 'selected', 19);
      }
      if ($line['icon'] !== null) {
        $iconBox = new CanvasRectangle($g['sectionX'] + 2, $y + 1, $g['gutter'] - 6, $m->cellHeight - 2);
        if (!$view->icon('journal-icon-' . $index, $line['icon'], $iconBox)) {
          $fallback = match ($line['icon']) { 'objective.complete' => "\u{2713}", 'objective.open' => "\u{25CB}", default => '' };
        }
      }
      $markers[] = ['text' => $fallback, 'color' => $line['color']];
    }
    $view->textLines('journal-text', array_map(fn($line) => ['text' => $line['text'], 'color' => $line['color']], $styles), $g['text']);
    $view->textLines('journal-markers', $markers, new CanvasRectangle($g['sectionX'] + 2, $g['text']->y,
      $g['gutter'] - 6, $g['text']->height));
  }

  private static function geometry(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width, int $height): array
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $w = min(1100, $width - 20);
    $h = min(700, $height - 20);
    $x = ($width - $w) / 2;
    $y = ($height - $h) / 2;
    $leftWidth = floor($w * 0.38);
    $rightX = $x + $leftWidth + $gap;
    $rightWidth = $w - $leftWidth - $gap;
    $gutter = max(28, (int)ceil($theme->rows->metrics->iconWidth + 8));
    $headerHeight = max(80, 2 * $p + $m->rowHeight);
    $tabWidth = ($rightWidth - 2 * $p) / max(1, count($content->tabs));
    if ($tabWidth > 2 * $theme->rows->metrics->padding + $m->cellWidth) {
      $tabLayout = new MenuRowLayout(new CanvasRectangle(0, 0, $tabWidth, 1), rowHeight: $m->rowHeight,
        cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true);
      foreach ($content->tabs as $index => $label) {
        $headerHeight = max($headerHeight, 2 * $p + $tabLayout->heightFor(new MenuRow((string)$index, $label,
          kind: MenuRowKind::BUTTON), $theme->rows->metrics, false));
      }
    }
    $hintHeight = $theme->showInputHints ? MenuActionHints::height([ActionHints::resolve('confirm', 'Read'),
      ActionHints::resolve('cancel', 'Back')], $theme, $rightWidth - 2 * $p) + $gap : 0;
    $textHeight = floor(($h - $headerHeight - 2 * $p - $hintHeight) / $m->cellHeight) * $m->cellHeight;
    $textWidth = $rightWidth - 2 * $p - $gutter - 12;
    if ($textHeight < 3 * $m->cellHeight || $textWidth < 8 * $m->cellWidth || $leftWidth < 2 * $p + 120) {
      throw new RuntimeException('Journal theme leaves no readable list and detail viewport; terminal presentation retained.');
    }
    $textY = $y + $headerHeight + $p;
    return ['header' => new CanvasRectangle($x, $y, $w, $headerHeight),
      'list' => new CanvasRectangle($x, $y + $headerHeight, $leftWidth, $h - $headerHeight),
      'detail' => new CanvasRectangle($rightX, $y + $headerHeight, $rightWidth, $h - $headerHeight),
      'text' => new CanvasRectangle($rightX + $p + $gutter, $textY, $textWidth, $textHeight),
      'scroll' => new CanvasRectangle($rightX + $rightWidth - $p - 4, $textY, 4, $textHeight),
      'hints' => new CanvasRectangle($rightX + $p, $y + $h - $p - max(1, $hintHeight - $gap),
        $rightWidth - 2 * $p, max(1, $hintHeight - $gap)),
      'sectionX' => $rightX + $p, 'sectionWidth' => $rightWidth - 2 * $p - 12, 'gutter' => $gutter];
  }

  private static function inset(CanvasRectangle $box, int $p): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $p, $box->y + $p, $box->width - 2 * $p, $box->height - 2 * $p);
  }
}
