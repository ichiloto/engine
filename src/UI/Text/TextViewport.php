<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Text;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** PHP-owned reading position, anchored in source text across viewport reflow. */
final class TextViewport
{
  private string $text = '';
  private int $offset = 0;

  /** Owners call this on entry/selection/content changes, never from a painter. */
  public function setText(string $text): void
  {
    $text = self::normalize($text);
    if ($text !== $this->text) { $this->text = $text; $this->reset(); }
  }

  /** The source form every reading position is anchored in; structured documents build theirs with it. */
  public static function normalize(string $text): string
  {
    return str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], TerminalText::stripAnsi($text));
  }

  public function reset(): void { $this->offset = 0; }

  /** Explicit list preview; owners must expose the complete value in their detail page. */
  public static function preview(string $text, int $columns): string
  {
    if ($columns < 4) { throw new InvalidArgumentException('A readable preview requires at least four cells.'); }
    $text = self::normalize($text);
    $lines = self::wrap($text, $columns);
    if (count($lines) === 1) { return $lines[0][1]; }
    $prefix = '';
    $width = 0;
    foreach (TerminalText::visibleSymbols($lines[0][1]) as $symbol) {
      $size = max(TerminalText::getSymbolWidth($symbol), mb_strlen($symbol, 'UTF-8'));
      if ($width + $size > $columns - 3) { break; }
      $prefix .= $symbol;
      $width += $size;
    }
    return rtrim($prefix) . '...';
  }

  /** A render reads a clamped snapshot without changing the reading position. */
  public function page(int $columns, int $rows): TextPage
  {
    if ($columns < 1 || $rows < 1) { throw new InvalidArgumentException('Text viewport needs positive columns and rows.'); }
    return $this->createLayoutPage(self::wrap($this->text, $columns), $columns, $rows);
  }

  /** A structured document supplies its own lines, anchored in offsets of the same source text.
   * @param non-empty-list<array{int, string}> $wrapped Ascending source byte offset and displayed line.
   */
  public function createLayoutPage(array $wrapped, int $columns, int $rows): TextPage
  {
    if ($columns < 1 || $rows < 1 || $wrapped === []) { throw new InvalidArgumentException('Text viewport needs positive columns, rows and lines.'); }
    $first = 0;
    foreach ($wrapped as $index => [$offset]) {
      if ($offset > $this->offset) { break; }
      $first = $index;
    }
    $maximum = max(0, count($wrapped) - $rows);
    $first = min($first, $maximum);
    return new TextPage($this->text, array_column(array_slice($wrapped, $first, $rows), 1), $first, count($wrapped),
      $columns, $rows, $wrapped[max(0, $first - 1)][0], $wrapped[min($maximum, $first + 1)][0]);
  }

  /** Semantic navigation supplies direction and the last successfully displayed PHP layout. */
  public function scroll(int $direction, TextPage $page): void
  {
    if ($direction === 0) { return; }
    if ($page->source !== $this->text) { $page = $this->page($page->columns, $page->rows); }
    $this->offset = $direction > 0 ? $page->nextOffset : $page->previousOffset;
  }

  /** Cells a single line occupies under the same budget as wrap(), for aligning structured columns. */
  public static function measure(string $text): int
  {
    preg_match_all('/\X/u', $text, $symbols);
    return array_sum(array_map(static fn(string $symbol): int => max(TerminalText::getSymbolWidth($symbol),
      mb_strlen($symbol, 'UTF-8')), $symbols[0]));
  }

  /** Complete graphemes fit both terminal cell widths and Canvas scalar budgets.
   * @return non-empty-list<array{int, string}> Source byte offset and displayed line.
   */
  public static function wrap(string $text, int $columns): array
  {
    if ($columns < 1 || !mb_check_encoding($text, 'UTF-8')) { throw new InvalidArgumentException('Text requires UTF-8 and a positive viewport width.'); }
    preg_match_all('/\n|[^\S\n\x{00A0}\x{202F}]+|[\S\x{00A0}\x{202F}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
    $lines = [];
    $line = '';
    $width = 0;
    $start = 0;
    foreach ($matches[0] as [$token, $offset]) {
      if ($token === "\n") {
        $lines[] = [$start, $line];
        $line = '';
        $width = 0;
        $start = $offset + 1;
        continue;
      }
      preg_match_all('/\X/u', $token, $symbols, PREG_OFFSET_CAPTURE);
      $sizes = array_map(fn($item) => max(TerminalText::getSymbolWidth($item[0]), mb_strlen($item[0], 'UTF-8')), $symbols[0]);
      $tokenWidth = array_sum($sizes);
      $whitespace = preg_match('/\A[^\S\x{00A0}\x{202F}]+\z/u', $token) === 1;
      if (!$whitespace && $width > 0 && $width + $tokenWidth > $columns
        && ($tokenWidth <= $columns || preg_match('/\S/u', $line) === 1)) {
        $lines[] = [$start, $line];
        $line = '';
        $width = 0;
        $start = $offset;
      }
      foreach ($symbols[0] as $index => [$symbol, $relative]) {
        $size = $sizes[$index];
        if ($size > $columns) { throw new InvalidArgumentException('A complete text grapheme exceeds the viewport width.'); }
        if ($width + $size > $columns) {
          $lines[] = [$start, $line];
          $line = '';
          $width = 0;
          $start = $offset + $relative;
        }
        $line .= $symbol;
        $width += $size;
      }
    }
    $lines[] = [$start, $line];
    return $lines;
  }
}
