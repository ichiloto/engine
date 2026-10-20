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
  // Quest names use the narrow column; the journal receives the majority for prose.
  private const float LIST_WIDTH_SHARE = 0.38;
  public static function pageSize(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): MenuDetailViewport
  {
    $text = self::getGeometry($content, $theme, $width, $height)['text'];
    return new MenuDetailViewport((int)floor($text->width / $theme->metrics->cellWidth), (int)floor($text->height),
      $theme->metrics->cellHeight);
  }

  public static function compose(JournalMenuContent $content, MenuPresentationCatalog $theme, TextPage $page,
    float $time = 0, int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $g = self::getGeometry($content, $theme, $width, $height);
    $area = self::pageSize($content, $theme, $width, $height);
    if ($page->columns !== $area->columns || $page->rows !== $area->height || $page->source !== $content->document?->text) {
      throw new RuntimeException('Journal text page must match the measured document viewport.');
    }
    $view = new MenuCanvas($theme, $width, $height, $time);
    $controls = new MenuControls($theme);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    foreach (['header', 'list', 'detail'] as $role) {
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
    if ($page->total > count($page->lines)) {
      $controls->renderScrollbar('journal-scroll', $g['scroll'], $page->first, count($page->lines), $page->total);
    }
    if ($theme->showInputHints) {
      $view->hints('journal-hints', [ActionHints::resolve('confirm', 'Read'), ActionHints::resolve('cancel', 'Back')], $g['hints']);
    }
    return MenuCanvas::overlay($view->finish(), $controls->finish($width, $height), $theme);
  }

  private static function document(MenuCanvas $view, JournalDocument $document, TextPage $page, array $g): void
  {
    $m = $view->theme->metrics;
    $styles = array_slice($document->lines($page->columns, $view->theme->details), $page->first, count($page->lines));
    $positions = $view->details('journal-text', $document->sheet->layout($page->columns, $view->theme->details), $page, $g['text']);
    $sections = [];
    foreach ($styles as $index => $line) {
      if ($line['section'] !== null) { $sections[$line['section']][] = $index; }
    }
    // Each visible section has its own bounded surface. Scrolling never puts text under a frame edge.
    foreach ($sections as $section => $indexes) {
      $top = $positions[min($indexes)]['y'];
      $bottom = $positions[max($indexes)]['y'] + $positions[max($indexes)]['height'];
      $view->surface('journal-section-' . $section, new CanvasRectangle($g['sectionX'], $top,
        $g['sectionWidth'], $bottom - $top), 'background', 18);
    }
    // Marker fallbacks share one text layer for each run of lines at one text pitch, as the text does.
    $blocks = [];
    foreach ($styles as $index => $line) {
      $y = $positions[$index]['y'];
      if ($line['heading']) {
        $view->surface('journal-section-heading-' . $index, new CanvasRectangle($g['sectionX'], $y,
          $g['sectionWidth'], $m->cellHeight), 'selected', 19);
      }
      $fallback = '';
      if ($line['icon'] !== null) {
        $iconBox = new CanvasRectangle($g['sectionX'] + 2, $y + 1, $g['gutter'] - 6, $m->cellHeight - 2);
        $fallback = $view->icon('journal-icon-' . $index, $line['icon'], $iconBox) ? ''
          : match ($line['icon']) { 'objective.complete' => "\u{2713}", 'objective.open' => "\u{25CB}", default => '' };
      }
      $last = array_key_last($blocks);
      if ($last === null || abs($blocks[$last]['y'] + count($blocks[$last]['lines']) * $m->cellHeight - $y) > 0.001) {
        $blocks[] = ['y' => $y, 'lines' => []];
        $last = array_key_last($blocks);
      }
      $blocks[$last]['lines'][] = ['text' => $fallback, 'color' => $line['color']];
    }
    foreach ($blocks as $index => ['y' => $y, 'lines' => $markers]) {
      if (array_all($markers, static fn(array $marker): bool => $marker['text'] === '')) { continue; }
      $view->textLines($index === 0 ? 'journal-markers' : 'journal-markers-' . $index, $markers,
        new CanvasRectangle($g['sectionX'] + 2, $y, $g['gutter'] - 6, count($markers) * $m->cellHeight));
    }
  }

  private static function getGeometry(JournalMenuContent $content, MenuPresentationCatalog $theme, int $width, int $height): array
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $host = MenuLayout::getBounds($width, $height);
    [$x, $y, $w, $h] = [$host->x, $host->y, $host->width, $host->height];
    $leftWidth = floor($w * self::LIST_WIDTH_SHARE);
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
    $scrollSpace = $m->scrollbarWidth + $gap;
    $textWidth = $rightWidth - 2 * $p - $gutter - $scrollSpace;
    if ($textHeight < 3 * $m->cellHeight || $textWidth < 8 * $m->cellWidth || $leftWidth < 2 * $p + 120) {
      throw new RuntimeException('Journal theme leaves no readable list and detail viewport; terminal presentation retained.');
    }
    $textY = $y + $headerHeight + $p;
    return ['header' => new CanvasRectangle($x, $y, $w, $headerHeight),
      'list' => new CanvasRectangle($x, $y + $headerHeight, $leftWidth, $h - $headerHeight),
      'detail' => new CanvasRectangle($rightX, $y + $headerHeight, $rightWidth, $h - $headerHeight),
      'text' => new CanvasRectangle($rightX + $p + $gutter, $textY, $textWidth, $textHeight),
      'scroll' => new CanvasRectangle($rightX + $rightWidth - $p - $m->scrollbarWidth,
        $textY, $m->scrollbarWidth, $textHeight),
      'hints' => new CanvasRectangle($rightX + $p, $y + $h - $p - max(1, $hintHeight - $gap),
        $rightWidth - 2 * $p, max(1, $hintHeight - $gap)),
      'sectionX' => $rightX + $p, 'sectionWidth' => $rightWidth - 2 * $p - $scrollSpace, 'gutter' => $gutter];
  }

  private static function inset(CanvasRectangle $box, int $p): CanvasRectangle
  {
    return new CanvasRectangle($box->x + $p, $box->y + $p, $box->width - 2 * $p, $box->height - 2 * $p);
  }
}
