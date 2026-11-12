<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;
use Ichiloto\Engine\IO\Console\TerminalText;

/** Word-aware wrapping for the existing one-codepoint-per-cell Canvas contract. */
final class MenuTextWrap
{
  /**
   * One line of text in a number of cells: the whole text when it fits,
   * else as much as fits with an ellipsis in its last cell, so a cut is seen.
   */
  public static function ellipsize(string $text, int $cells): string
  {
    if ($cells < 1 || !mb_check_encoding($text, 'UTF-8')) {
      throw new InvalidArgumentException('Menu text requires UTF-8 and a positive cell width.');
    }
    $text = str_replace("\n", ' ', self::normalizeText($text));
    return mb_strlen($text, 'UTF-8') <= $cells ? $text : rtrim(mb_substr($text, 0, $cells - 1, 'UTF-8')) . "\u{2026}";
  }

  /**
   * Text wrapped into at most a number of lines: every line when they fit,
   * else the first lines and an ellipsized last line, so a cut is seen.
   *
   * @return non-empty-list<string>
   */
  public static function fitLines(string $text, int $cells, int $lines): array
  {
    if ($lines < 1) {
      throw new InvalidArgumentException('Menu text requires at least one line.');
    }
    $wrapped = self::lines($text, $cells);
    if (count($wrapped) <= $lines) {
      return $wrapped;
    }
    $kept = array_slice($wrapped, 0, $lines - 1);
    $kept[] = self::ellipsize(implode('', array_slice($wrapped, $lines - 1)), $cells);
    return $kept;
  }

  /** @return non-empty-list<string> Complete text, with whitespace retained at wrap boundaries. */
  public static function lines(string $text, int $cells): array
  {
    if ($cells < 1 || !mb_check_encoding($text, 'UTF-8')) {
      throw new InvalidArgumentException('Menu text requires UTF-8 and a positive cell width.');
    }
    $lines = [];
    $text = self::normalizeText($text);
    foreach (explode("\n", $text) as $paragraph) {
      // Nonbreaking spaces remain inside their word. Hyphens do not introduce a break.
      preg_match_all('/[^\S\x{00A0}\x{202F}]+|[\S\x{00A0}\x{202F}]+/u', $paragraph, $matches);
      $line = '';
      $width = 0;
      foreach ($matches[0] as $token) {
        $length = mb_strlen($token, 'UTF-8');
        $whitespace = preg_match('/\A[^\S\x{00A0}\x{202F}]+\z/u', $token) === 1;
        if (!$whitespace && $width > 0 && $width + $length > $cells
          && ($length <= $cells || preg_match('/\S/u', $line) === 1)) {
          $lines[] = $line;
          $line = '';
          $width = 0;
        }
        for ($offset = 0; $offset < $length;) {
          if ($width === $cells) {
            $lines[] = $line;
            $line = '';
            $width = 0;
          }
          $take = min($cells - $width, $length - $offset);
          $line .= mb_substr($token, $offset, $take, 'UTF-8');
          $width += $take;
          $offset += $take;
        }
      }
      $lines[] = $line;
    }
    return $lines;
  }

  /**
   * Original paragraph slices that fit a graphical reading area, without inserting soft newlines.
   * Independent styled symbols retain their current colours across page boundaries.
   * @return non-empty-list<string>
   */
  public static function paginate(string $text, int $cells, int $rows): array
  {
    if ($cells < 1 || $rows < 1 || !mb_check_encoding($text, 'UTF-8')) {
      throw new InvalidArgumentException('Reading pages require UTF-8 text and positive columns and rows.');
    }
    $symbols = TerminalText::visibleSymbols(self::normalizeText($text));
    $pages = [];
    for ($offset = 0, $count = count($symbols); $offset < $count;) {
      $low = 1; $high = $count - $offset; $take = 0;
      while ($low <= $high) {
        $middle = intdiv($low + $high, 2);
        $candidate = implode('', array_slice($symbols, $offset, $middle));
        if (count(self::lines(TerminalText::stripAnsi($candidate), $cells)) <= $rows) {
          $take = $middle; $low = $middle + 1;
        } else { $high = $middle - 1; }
      }
      // A lone authored paragraph break can itself fill a one-row blank page.
      if ($take === 0 && TerminalText::stripAnsi($symbols[$offset]) === "\n") {
        $pages[] = ''; $offset++; continue;
      }
      if ($take === 0) { throw new InvalidArgumentException('A reading symbol cannot fit its finite page.'); }
      if ($offset + $take < $count && preg_match('/[^\S\x{00A0}\x{202F}]/u', TerminalText::stripAnsi($symbols[$offset + $take])) !== 1
        && preg_match('/[^\S\x{00A0}\x{202F}]/u', TerminalText::stripAnsi($symbols[$offset + $take - 1])) !== 1) {
        for ($boundary = $take - 1; $boundary > 0; $boundary--) {
          if (preg_match('/[^\S\x{00A0}\x{202F}]/u', TerminalText::stripAnsi($symbols[$offset + $boundary])) === 1) {
            $take = $boundary + 1; break;
          }
        }
      }
      $pages[] = implode('', array_slice($symbols, $offset, $take));
      $offset += $take;
    }
    return $pages === [] ? [''] : $pages;
  }

  /** Use the same display normalization for layout and an owner's visible prefix. */
  public static function normalizeText(string $text): string
  {
    return str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
  }
}
