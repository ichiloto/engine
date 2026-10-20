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

  public function layout(int $columns, MenuDetailStyle $style = new MenuDetailStyle()): MenuDetailLayout
  {
    if ($columns < 1) { throw new InvalidArgumentException('Detail layout needs a positive width.'); }
    $lines = [];
    $entry = 0;
    foreach ($this->sections as $section => $entries) {
      // One blank line separates sections; its offset is the separating newline, between both sections' text.
      if ($section > 0) { $lines[] = new MenuDetailLine($this->starts[$section][0] - 1); }
      $labels = array_map(static fn(MenuDetailEntry $field): int => TextViewport::measure($field->label . $style->labelSuffix),
        array_filter($entries, static fn(MenuDetailEntry $item): bool => $item->isField));
      $column = $labels === [] ? 0 : max($labels) + $style->gapCells;
      $narrow = $columns - $column < $style->minValueCells;
      foreach ($entries as $index => $item) {
        $start = $this->starts[$section][$index];
        if ($item->isField) {
          array_push($lines, ...self::layoutField($item, $start, $entry, $narrow ? null : $column, $columns, $style));
        } else {
          $heading = $item->kind === MenuDetailKind::HEADING;
          foreach (TextViewport::wrap($item->text, $columns) as [$offset, $text]) {
            $lines[] = new MenuDetailLine($start + $offset, $text, color: $heading ? $style->headingColor : ($item->color ?? 'text'),
              entry: $entry, isHeading: $heading);
          }
        }
        $entry++;
      }
    }
    return new MenuDetailLayout($this->text, $columns, $lines === [] ? [new MenuDetailLine(0)] : $lines, $style);
  }

  /** Syncs the owner's reading position to this sheet, then reads the page one renderer measured. */
  public function createPage(TextViewport $viewport, MenuDetailViewport $area, MenuDetailStyle $style = new MenuDetailStyle()): TextPage
  {
    $viewport->setText($this->text);
    $layout = $this->layout($area->columns, $style);
    return $viewport->createLayoutPage($layout->getWrappedLines(), $area->columns, $area->height,
      array_column($layout->measureLines($area->lineHeight), 'height'));
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
      $text = $field->kind === MenuDetailKind::ICONS && $field->icons === [] ? $style->emptyValueText : $field->text;
      if ($text === '') { return []; }
      return array_map(static function (array $line): array {
        $text = rtrim($line[1]);
        return ['offset' => $line[0], 'text' => $text, 'width' => TextViewport::measure($text), 'items' => []];
      }, TextViewport::wrap($text, $cells));
    }
    // Drawn icons take their cells; any icon the renderer lacks shows its name in its place.
    $pieces = [];
    $line = null;
    $offset = 0;
    foreach ($field->icons as $icon) {
      $role = in_array($icon->role, $style->iconRoles, true) ? $icon->role : null;
      $width = $role === null ? TextViewport::measure($icon->name) : $style->iconCells;
      if ($line !== null && $line['width'] + 1 + $width > $cells) { $pieces[] = $line; $line = null; }
      $column = $line === null ? 0 : $line['width'] + 1;
      $line ??= ['offset' => $offset, 'text' => '', 'width' => 0, 'items' => []];
      $line['items'][] = ['column' => $column, 'role' => $role, 'text' => $role === null ? $icon->name : ''];
      $line['text'] = trim($line['text'] . ' ' . $icon->name);
      $line['width'] = $column + $width;
      $offset += strlen($icon->name) + 2;
    }
    $pieces[] = $line;
    return $pieces;
  }
}
