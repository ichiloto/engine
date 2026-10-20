<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;
use InvalidArgumentException;

/**
 * Shared reading content for every renderer: prose paragraphs and label/value fields in visible sections.
 * Fields align their values in one column per section; Terminal names labels with its attached suffix,
 * Canvas draws the theme's record separators instead. Owners keep the reading position and navigation.
 */
final readonly class MenuDetailSheet
{
  /** A value column narrower than this places the label on its own line above the value. */
  public const int MIN_VALUE_CELLS = 12;
  public const int GAP_CELLS = 1;
  /** A stacked value sits under its label, indented so the label still leads the record. */
  public const int STACKED_INDENT_CELLS = 2;

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

  public function layout(int $columns, string $labelSuffix = ''): MenuDetailLayout
  {
    if ($columns < 1) { throw new InvalidArgumentException('Detail layout needs a positive width.'); }
    $lines = [];
    $entry = 0;
    foreach ($this->sections as $section => $entries) {
      // One blank line separates sections; its offset is the separating newline, between both sections' text.
      if ($section > 0) { $lines[] = new MenuDetailLine($this->starts[$section][0] - 1); }
      $labels = array_map(static fn(MenuDetailEntry $field): int => TextViewport::measure($field->label . $labelSuffix),
        array_filter($entries, static fn(MenuDetailEntry $item): bool => $item->isField));
      $column = $labels === [] ? 0 : max($labels) + self::GAP_CELLS;
      $stacked = $columns - $column < self::MIN_VALUE_CELLS;
      foreach ($entries as $index => $item) {
        $start = $this->starts[$section][$index];
        if (!$item->isField) {
          foreach (TextViewport::wrap($item->text, $columns) as [$offset, $text]) {
            $lines[] = new MenuDetailLine($start + $offset, $text, color: $item->color, entry: $entry);
          }
        } else {
          array_push($lines, ...self::layoutField($item, $start, $entry, $stacked ? null : $column, $columns, $labelSuffix));
        }
        $entry++;
      }
    }
    return new MenuDetailLayout($this->text, $columns, $lines === [] ? [new MenuDetailLine(0)] : $lines);
  }

  /** Syncs the owner's reading position to this sheet, then reads the page one renderer measured. */
  public function createPage(TextViewport $viewport, int $columns, int $rows, string $labelSuffix = ''): TextPage
  {
    $viewport->setText($this->text);
    return $viewport->createLayoutPage($this->layout($columns, $labelSuffix)->getWrappedLines(), $columns, $rows);
  }

  /** A null column stacks the label above its value.
   * @return non-empty-list<MenuDetailLine>
   */
  private static function layoutField(MenuDetailEntry $field, int $start, int $entry, ?int $column, int $columns, string $suffix): array
  {
    $valueStart = $start + strlen($field->label) + 1;
    $lines = [];
    $stacked = $column === null;
    if ($stacked) {
      foreach (TextViewport::wrap($field->label . $suffix, $columns) as [$offset, $label]) {
        $lines[] = new MenuDetailLine($start + min($offset, strlen($field->label)), label: $label, isField: true, entry: $entry);
      }
      $column = $columns > 2 * self::STACKED_INDENT_CELLS ? self::STACKED_INDENT_CELLS : 0;
    }
    foreach (TextViewport::wrap($field->text, $columns - $column) as $index => [$offset, $text]) {
      $first = !$stacked && $index === 0;
      $lines[] = new MenuDetailLine($first ? $start : $valueStart + $offset, $text, $column,
        $first ? $field->label . $suffix : '', $field->color, true, entry: $entry);
    }
    $last = array_pop($lines);
    $lines[] = new MenuDetailLine($last->offset, $last->text, $last->column, $last->label, $last->color, true, true, $entry);
    return $lines;
  }
}
