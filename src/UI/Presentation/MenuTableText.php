<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * Menu rows as Terminal text, laid out by the same columns a graphical menu
 * uses ({@see MenuRowColumn}): each row's label in one line, cut with an
 * ellipsis only when it cannot fit, then each column's cell, so figures
 * share their right edges from row to row as they do on the canvas.
 */
final class MenuTableText
{
  /** Cells between the label and the first column, and between columns. */
  public const int GAP = 2;

  /**
   * The cells a list's labels take: the widest label, within what the
   * columns leave of the width.
   *
   * @param list<string> $labels
   * @param list<MenuRowColumn> $columns
   */
  public static function getLabelCells(array $labels, array $columns, int $width): int
  {
    $widest = max([1, ...array_map(static fn(string $label): int => TerminalText::displayWidth($label), $labels)]);
    return max(1, min($widest, $width - self::getColumnCells($columns)));
  }

  /** @param list<MenuRowColumn> $columns The cells the columns take, with the gap before each. */
  public static function getColumnCells(array $columns): int
  {
    return array_sum(array_map(static fn(MenuRowColumn $column): int => ($column->gap ?? self::GAP) + $column->cells, $columns));
  }

  /**
   * One row: its label in the label cells, then each figure in its column.
   *
   * @param list<string> $figures One per column.
   * @param list<MenuRowColumn> $columns
   */
  public static function formatRow(string $label, array $figures, array $columns, int $labelCells): string
  {
    $text = TerminalText::padRight(MenuTextWrap::ellipsize($label === '' ? ' ' : $label, $labelCells), $labelCells);
    foreach ($columns as $index => $column) {
      $text .= str_repeat(' ', $column->gap ?? self::GAP) . $column->formatCell((string) ($figures[$index] ?? ''));
    }
    return $columns === [] ? rtrim($text) : $text;
  }
}
