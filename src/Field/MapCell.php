<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * One square map cell: two terminal columns, which is square on a terminal
 * whose character boxes are about twice as tall as wide, and one tile in a
 * graphical renderer. A cell is either one two-column glyph (an emoji or CJK
 * character) or two one-column characters, each keeping its own style. The
 * cell string is its styled symbols joined, so authored text round-trips.
 */
final class MapCell
{
  /** Terminal columns per map cell. */
  public const int COLUMNS = 2;
  public const string BLANK = '  ';
  private const int CACHE_LIMIT = 4096;
  /** @var array<string, list<string>> */
  private static array $characters = [];

  /**
   * Splits one authored row into whole cells.
   *
   * @return list<string>
   */
  public static function parseRow(string $line, string $context = 'Map row'): array
  {
    $cells = [];
    $pending = null;
    $column = 0;
    foreach (TerminalText::visibleSymbols($line) as $symbol) {
      $width = NormalizedRow::symbolWidth($symbol);
      if ($width >= self::COLUMNS) {
        if ($pending !== null) {
          throw new InvalidArgumentException("{$context}, column {$column}: a two-column glyph must begin a cell, not follow a lone character.");
        }
        $cells[] = $symbol;
      } elseif ($pending === null) {
        $pending = $symbol;
      } else {
        $cells[] = $pending . $symbol;
        $pending = null;
      }
      $column += $width;
    }
    if ($pending !== null) {
      throw new InvalidArgumentException("{$context} ends halfway through a cell; every cell is two columns wide.");
    }
    return $cells;
  }

  /**
   * The cell's characters without styles: two for a pair, one for a two-column glyph.
   *
   * @return list<string>
   */
  public static function getCharacters(string $cell): array
  {
    if (isset(self::$characters[$cell])) {
      return self::$characters[$cell];
    }
    if (count(self::$characters) >= self::CACHE_LIMIT) {
      self::$characters = [];
    }
    return self::$characters[$cell] = array_map(TerminalText::stripAnsi(...), TerminalText::visibleSymbols($cell));
  }

  public static function isBlank(string $cell): bool
  {
    return trim(TerminalText::stripAnsi($cell)) === '';
  }

  /**
   * The cell's character in one of its columns: a two-column glyph fills
   * both, and a pair has one character in each.
   */
  public static function getColumnCharacter(string $cell, int $column): string
  {
    $characters = self::getCharacters($cell);
    return count($characters) === 1 ? $characters[0] : ($characters[$column] ?? ' ');
  }

  /**
   * Lays an upper layer's cell over a lower one column by column, as the
   * terminal layers always composed: a space in either column of an upper
   * pair shows the lower layer's character there. A two-column glyph on
   * either side cannot be split, so a non-blank upper cell then replaces
   * the whole cell.
   */
  public static function overlay(string $lower, string $upper): string
  {
    if (self::isBlank($upper)) {
      return $lower;
    }
    $top = TerminalText::visibleSymbols($upper);
    $bottom = TerminalText::visibleSymbols($lower);
    if (count($top) !== self::COLUMNS || count($bottom) !== self::COLUMNS) {
      return $upper;
    }
    $cell = '';
    foreach ($top as $column => $symbol) {
      $cell .= trim(TerminalText::stripAnsi($symbol)) === '' ? $bottom[$column] : $symbol;
    }
    return $cell;
  }

  /**
   * The one character marking this cell (`E `, ` E` and `EE` all mark `E`), or null when blank.
   *
   * @throws InvalidArgumentException When the cell holds two different markers.
   */
  public static function getMarker(string $cell, string $context = 'Map cell'): ?string
  {
    $markers = array_values(array_unique(array_filter(self::getCharacters($cell),
      static fn(string $character): bool => trim($character) !== '')));
    if (count($markers) > 1) {
      throw new InvalidArgumentException("{$context} holds two different markers, " . implode(' and ', $markers) . '.');
    }
    return $markers[0] ?? null;
  }

  /** The cells a run of terminal text covers from a cell's first column; at least one. */
  public static function getSpanCells(int $columns): int
  {
    return max(1, intdiv($columns + self::COLUMNS - 1, self::COLUMNS));
  }

  /** @return list<string> One row of blank cells. */
  public static function getBlankRow(int $cells): array
  {
    return array_fill(0, max(0, $cells), self::BLANK);
  }
}
