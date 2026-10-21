<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/**
 * Shared reading content for every renderer: prose paragraphs and label/value fields in visible sections.
 * The renderer's MenuDetailStyle decides label punctuation, value alignment, colours and separators;
 * fields in a section share one value column. Owners keep the reading position and navigation.
 */
final readonly class MenuDetailSheet
{
  /** Source text that anchors the owner's reading position across reflow. */
  public string $text;
  /** @var list<non-empty-list<MenuDetailEntry>> */
  public array $sections;
  /** @var list<list<int>> */
  private array $starts;

  /** @param list<list<MenuDetailEntry>> $sections Owners may supply empty sections; they are omitted. */
  public function __construct(array $sections)
  {
    $visible = [];
    foreach ($sections as $entries) {
      if (!is_array($entries) || !array_is_list($entries) || !array_all($entries, static fn($entry) => $entry instanceof MenuDetailEntry)) {
        throw new InvalidArgumentException('Detail sections must be lists of typed entries.');
      }
      if ($entries !== []) { $visible[] = $entries; }
    }
    $text = '';
    $starts = [];
    foreach ($visible as $section => $entries) {
      if ($section > 0) { $text .= "\n\n"; }
      foreach ($entries as $index => $entry) {
        if ($index > 0) { $text .= "\n"; }
        $starts[$section][$index] = strlen($text);
        $text .= $entry->isField ? $entry->label . "\n" . $entry->text : $entry->text;
      }
    }
    $this->sections = $visible;
    $this->starts = $starts;
    $this->text = $text;
  }

  /** @return list<MenuDetailEntry> Entries in reading order; MenuDetailLine::$entry indexes this list. */
  public function getEntries(): array
  {
    return array_merge(...$this->sections);
  }

  /**
   * Lays the sheet out for one width. With a viewport and a style allowing several columns, sections after the
   * lead prose share columns when the whole sheet then fits; otherwise they stack and the owner scrolls them.
   */
  public function layout(int $columns, MenuDetailStyle $style = new MenuDetailStyle(), ?MenuDetailViewport $area = null): MenuDetailLayout
  {
    if ($columns < 1) { throw new InvalidArgumentException('Detail layout needs a positive width.'); }
    $bases = [];
    $entry = 0;
    foreach ($this->sections as $index => $entries) { $bases[$index] = $entry; $entry += count($entries); }
    $columnar = $area === null ? null : $this->layoutColumns($columns, $style, $area, $bases);
    if ($columnar !== null) { return new MenuDetailLayout($this->text, $columns, $columnar, $style, true); }
    $lines = [];
    foreach (array_keys($this->sections) as $section) {
      if ($section > 0) { $lines[] = new MenuDetailLine($this->starts[$section][0] - 1); }
      array_push($lines, ...$this->layoutSection($section, $bases[$section], $columns, $style));
    }
    return new MenuDetailLayout($this->text, $columns, $lines === [] ? [new MenuDetailLine(0)] : $lines, $style);
  }

  /** Syncs the owner's reading position to this sheet, then reads the page one renderer measured. */
  public function createPage(TextViewport $viewport, MenuDetailViewport $area, MenuDetailStyle $style = new MenuDetailStyle()): TextPage
  {
    $viewport->setText($this->text);
    $layout = $this->layout($area->columns, $style, $area);
    $heights = array_column($layout->measureLines($area->lineHeight), 'height');
    // A column arrangement is only chosen when everything fits, so its page is the whole sheet.
    return $viewport->createLayoutPage($layout->getWrappedLines(), $area->columns,
      $layout->isColumnar ? max($area->height, array_sum($heights)) : $area->height, $heights);
  }

  /** @return list<MenuDetailLine> One section's lines; fields share the section's label column. */
  private function layoutSection(int $section, int $entry, int $columns, MenuDetailStyle $style): array
  {
    $entries = $this->sections[$section];
    $labels = array_map(static fn(MenuDetailEntry $field): int => TextViewport::measure($field->label . $style->labelSuffix),
      array_filter($entries, static fn(MenuDetailEntry $item): bool => $item->isField));
    $column = $labels === [] ? 0 : max($labels) + $style->gapCells;
    // The minimum label column applies only while a useful value column remains.
    if ($column > 0 && $columns - ($style->minLabelCells + $style->gapCells) >= $style->minValueCells) {
      $column = max($column, $style->minLabelCells + $style->gapCells);
    }
    $narrow = $columns - $column < $style->minValueCells;
    $lines = [];
    foreach ($entries as $index => $item) {
      $start = $this->starts[$section][$index];
      if ($item->isField) {
        array_push($lines, ...self::layoutField($item, $start, $entry, $narrow ? null : $column, $columns, $style));
        if ($style->separators && $style->separatorText !== '') {
          // A text-cell rule closes the record on its own line, anchored at the newline that ends the entry.
          $lines[] = new MenuDetailLine($start + strlen($item->label) + 1 + strlen($item->text),
            str_repeat($style->separatorText, max(1, intdiv($columns, max(1, TextViewport::measure($style->separatorText))))),
            color: 'edge', entry: $entry, isRule: true);
        }
      } else {
        $heading = $item->kind === MenuDetailKind::HEADING;
        foreach (TextViewport::wrap($item->text, $columns) as [$offset, $text]) {
          $lines[] = new MenuDetailLine($start + $offset, $text, color: $heading ? $style->headingColor : ($item->color ?? 'text'),
            entry: $entry, isHeading: $heading);
        }
      }
      $entry++;
    }
    return $lines;
  }

  /**
   * Lead sections (before the first headed section) span the sheet; headed sections split, in order, into the
   * columns that minimise the tallest column. Null when no arrangement fits the viewport.
   *
   * @param array<int, int> $bases First entry index of each section.
   * @return list<MenuDetailLine>|null
   */
  private function layoutColumns(int $columns, MenuDetailStyle $style, MenuDetailViewport $area, array $bases): ?array
  {
    $first = array_find_key($this->sections, static fn(array $entries): bool => $entries[0]->kind === MenuDetailKind::HEADING);
    if ($style->columns < 2 || $first === null || count($this->sections) - $first < 2) { return null; }
    $lead = [];
    for ($section = 0; $section < $first; $section++) {
      if ($section > 0) { $lead[] = new MenuDetailLine($this->starts[$section][0] - 1); }
      array_push($lead, ...$this->layoutSection($section, $bases[$section], $columns, $style));
    }
    if ($lead !== []) { $lead[] = new MenuDetailLine($this->starts[$first][0] - 1); }
    $leadHeight = array_sum(array_column(MenuDetailLayout::measure($lead, $style, $area->lineHeight), 'height'));
    $headed = range($first, count($this->sections) - 1);
    for ($count = min($style->columns, count($headed)); $count >= 2; $count--) {
      $cells = intdiv($columns - ($count - 1) * $style->columnGapCells, $count);
      if ($cells < $style->minColumnCells) { continue; }
      $blocks = [];
      foreach ($headed as $section) {
        $blocks[$section] = $this->layoutSection($section, $bases[$section], $cells, $style);
      }
      $heights = array_map(static fn(array $lines): int => array_sum(array_column(
        MenuDetailLayout::measure($lines, $style, $area->lineHeight), 'height')), $blocks);
      $groups = self::splitInOrder($headed, $heights, $count, $area->lineHeight);
      $tallest = max(array_map(static fn(array $group): int => array_sum(array_map(
        static fn(int $section): int => $heights[$section], $group)) + (count($group) - 1) * $area->lineHeight, $groups));
      if ($leadHeight + $tallest > $area->height) { continue; }
      $lines = $lead;
      foreach ($groups as $band => $group) {
        foreach ($group as $position => $section) {
          $placed = [...($position > 0 ? [new MenuDetailLine($this->starts[$section][0] - 1)] : []), ...$blocks[$section]];
          foreach ($placed as $line) { $lines[] = $line->withPlacement($band * ($cells + $style->columnGapCells), $band, $cells); }
        }
      }
      return $lines;
    }
    return null;
  }

  /**
   * Contiguous groups, in order, whose tallest group (sections plus one spacer line between them) is smallest.
   *
   * @param list<int> $sections
   * @param array<int, int> $heights
   * @return list<list<int>>
   */
  private static function splitInOrder(array $sections, array $heights, int $count, int $spacer): array
  {
    if ($count === 1) { return [$sections]; }
    $best = null;
    $bestHeight = PHP_INT_MAX;
    for ($cut = 1; $cut <= count($sections) - $count + 1; $cut++) {
      $head = array_slice($sections, 0, $cut);
      $rest = self::splitInOrder(array_slice($sections, $cut), $heights, $count - 1, $spacer);
      $tallest = max(array_map(static fn(array $group): int => array_sum(array_map(
        static fn(int $section): int => $heights[$section], $group)) + (count($group) - 1) * $spacer, [$head, ...$rest]));
      if ($tallest < $bestHeight) { $bestHeight = $tallest; $best = [$head, ...$rest]; }
    }
    return $best;
  }

  /** A null column, a kind placed below its label, or a wrapping value the style stacks puts the value under the label.
   * @return non-empty-list<MenuDetailLine>
   */
  private static function layoutField(MenuDetailEntry $field, int $start, int $entry, ?int $column, int $columns,
    MenuDetailStyle $style): array
  {
    $valueStart = $start + strlen($field->label) + 1;
    $color = $field->color ?? $style->valueColor;
    $below = $style->isPlacedBelow($field->kind);
    $stacked = $column === null || $below
      || ($style->stackWrappedValues && count(self::layoutValue($field, $columns - $column, $style)) > 1);
    $alignment = $stacked && !$below ? $style->stackedValueAlignment : $style->getAlignment($field->kind);
    $lines = [];
    if ($stacked) {
      foreach (TextViewport::wrap($field->label . $style->labelSuffix, $columns) as [$offset, $label]) {
        $lines[] = new MenuDetailLine($start + min($offset, strlen($field->label)), label: $label, isField: true, entry: $entry);
      }
      $column = $columns > 2 * $style->stackedIndentCells ? $style->stackedIndentCells : 0;
    }
    $cells = $columns - $column;
    foreach (self::layoutValue($field, $cells, $style) as $index => $piece) {
      $first = !$stacked && $index === 0;
      $shift = $alignment === HorizontalAlignment::RIGHT ? $cells - $piece['width'] : 0;
      $lines[] = new MenuDetailLine($first ? $start : $valueStart + $piece['offset'], $piece['text'], $column + $shift,
        $first ? $field->label . $style->labelSuffix : '', $color, true, entry: $entry,
        items: array_map(static fn(array $item): array => [...$item, 'column' => $item['column'] + $column + $shift], $piece['items']));
    }
    if (!$stacked && $lines === []) {
      $lines[] = new MenuDetailLine($start, label: $field->label . $style->labelSuffix, isField: true, entry: $entry);
    }
    $last = array_pop($lines);
    $lines[] = new MenuDetailLine($last->offset, $last->text, $last->column, $last->label, $last->color, true, true,
      $entry, items: $last->items);
    return $lines;
  }

  /** A value's lines for a column width: wrapped text, or icons and fallback names in rows of cells.
   * @return list<array{offset: int, text: string, width: int, items: list<array{column: int, role: ?string, text: string}>}>
   */
  private static function layoutValue(MenuDetailEntry $field, int $cells, MenuDetailStyle $style): array
  {
    $drawn = array_filter($field->icons, static fn(MenuDetailIcon $icon): bool => in_array($icon->role, $style->iconRoles, true));
    if ($field->kind !== MenuDetailKind::ICONS || $drawn === []) {
      $text = $field->kind === MenuDetailKind::ICONS && $field->icons === [] ? $style->getEmptyValueText() : $field->text;
      if ($text === '') { return []; }
      return array_map(static function (array $line): array {
        $text = rtrim($line[1]);
        return ['offset' => $line[0], 'text' => $text, 'width' => TextViewport::measure($text), 'items' => []];
      }, TextViewport::wrap($text, $cells));
    }
    // Drawn icons take their cells, followed by their names when the style shows them; any icon the renderer
    // lacks shows its name in its place. Items wrap in source order, never splitting an icon from its name.
    $pieces = [];
    $line = null;
    $offset = 0;
    foreach ($field->icons as $icon) {
      $role = in_array($icon->role, $style->iconRoles, true) ? $icon->role : null;
      $name = TextViewport::measure($icon->name);
      $width = $role === null ? $name : $style->iconCells + ($style->showIconNames ? 1 + $name : 0);
      if ($line !== null && $line['width'] + $style->iconGapCells + $width > $cells) { $pieces[] = $line; $line = null; }
      $column = $line === null ? 0 : $line['width'] + $style->iconGapCells;
      $line ??= ['offset' => $offset, 'text' => '', 'width' => 0, 'items' => []];
      if ($role !== null) { $line['items'][] = ['column' => $column, 'role' => $role, 'text' => '']; }
      if ($role === null || $style->showIconNames) {
        $line['items'][] = ['column' => $role === null ? $column : $column + $style->iconCells + 1, 'role' => null, 'text' => $icon->name];
      }
      $line['text'] = trim($line['text'] . ' ' . $icon->name);
      $line['width'] = $column + $width;
      $offset += strlen($icon->name) + 2;
    }
    $pieces[] = $line;
    return $pieces;
  }
}
