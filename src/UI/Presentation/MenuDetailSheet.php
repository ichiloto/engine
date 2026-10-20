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
      $stacked = $columns - $column < $style->minValueCells;
      foreach ($entries as $index => $item) {
        $start = $this->starts[$section][$index];
        if (!$item->isField) {
          foreach (TextViewport::wrap($item->text, $columns) as [$offset, $text]) {
            $lines[] = new MenuDetailLine($start + $offset, $text, color: $item->color ?? 'text', entry: $entry);
          }
        } else {
          array_push($lines, ...self::layoutField($item, $start, $entry, $stacked ? null : $column, $columns, $style));
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

  /** A null column, or a wrapping value the style stacks, places the label above its indented value.
   * @return non-empty-list<MenuDetailLine>
   */
  private static function layoutField(MenuDetailEntry $field, int $start, int $entry, ?int $column, int $columns,
    MenuDetailStyle $style): array
  {
    $valueStart = $start + strlen($field->label) + 1;
    $color = $field->color ?? $style->valueColor;
    $lines = [];
    $stacked = $column === null || ($style->stackWrappedValues && count(TextViewport::wrap($field->text, $columns - $column)) > 1);
    $alignment = $stacked ? $style->stackedValueAlignment : $style->valueAlignment;
    if ($stacked) {
      foreach (TextViewport::wrap($field->label . $style->labelSuffix, $columns) as [$offset, $label]) {
        $lines[] = new MenuDetailLine($start + min($offset, strlen($field->label)), label: $label, isField: true, entry: $entry);
      }
      $column = $columns > 2 * $style->stackedIndentCells ? $style->stackedIndentCells : 0;
    }
    $cells = $columns - $column;
    foreach (TextViewport::wrap($field->text, $cells) as $index => [$offset, $text]) {
      $first = !$stacked && $index === 0;
      // Right-aligned values end at the sheet's edge; wrapping whitespace does not count toward the edge.
      $text = $alignment === HorizontalAlignment::RIGHT ? rtrim($text) : $text;
      $shift = $alignment === HorizontalAlignment::RIGHT ? $cells - TextViewport::measure($text) : 0;
      $lines[] = new MenuDetailLine($first ? $start : $valueStart + $offset, $text, $column + $shift,
        $first ? $field->label . $style->labelSuffix : '', $color, true, entry: $entry);
    }
    $last = array_pop($lines);
    $lines[] = new MenuDetailLine($last->offset, $last->text, $last->column, $last->label, $last->color, true, true, $entry);
    return $lines;
  }
}
