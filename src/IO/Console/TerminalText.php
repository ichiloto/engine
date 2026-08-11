<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\IO\Enumerations\Color;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Utility helpers for terminal-safe text measurement and formatting.
 *
 * These helpers treat ANSI color sequences as zero-width and work with
 * grapheme clusters so emoji and other multibyte characters do not get split
 * mid-symbol when rendering menus or map rows.
 *
 * @package Ichiloto\Engine\IO\Console
 */
final class TerminalText
{
  /**
   * The most symbols kept in the per-symbol metric caches. Alphabets are
   * small in practice; the bound only guards against unbounded growth from
   * procedurally generated content.
   */
  private const int SYMBOL_CACHE_LIMIT = 4096;

  /**
   * Matches ANSI control sequences that should not count toward display width.
   */
  private const string ANSI_PATTERN = '/\x1B\[[0-9;?]*[ -\/]*[@-~]/';
  /**
   * Matches Symfony formatter tags such as <info> or <fg=blue>.
   */
  private const string FORMATTER_TAG_PATTERN = '/<\/?[-\w=;#,?]+>/';
  /**
   * @var OutputFormatter|null Shared formatter used to normalize style tags.
   */
  private static ?OutputFormatter $formatter = null;

  /**
   * TerminalText constructor.
   */
  private function __construct()
  {
  }

  /**
   * Removes ANSI control codes from the given text.
   *
   * @param string $text The text to clean.
   * @return string The visible text.
   */
  public static function stripAnsi(string $text): string
  {
    $text = self::formatStyles($text);
    return preg_replace(self::ANSI_PATTERN, '', $text) ?? $text;
  }

  /**
   * Returns the first visible symbol in the text.
   *
   * @param string $text The text to inspect.
   * @return string The first visible symbol, or an empty string if none exists.
   */
  public static function firstSymbol(string $text): string
  {
    return self::visibleSymbols($text)[0] ?? '';
  }

  /**
   * Returns the number of visible symbols in the text.
   *
   * This is useful for world-space slicing where map coordinates are based on
   * logical tiles rather than terminal cell width.
   *
   * @param string $text The text to measure.
   * @return int The number of visible symbols.
   */
  public static function symbolCount(string $text): int
  {
    return count(self::visibleSymbols($text));
  }

  /**
   * Returns the display width, in terminal cells, for the given text.
   *
   * @param string $text The text to measure.
   * @return int The display width.
   */
  public static function displayWidth(string $text): int
  {
    $width = 0;

    foreach (self::visibleSymbols($text) as $symbol) {
      $width += self::getSymbolWidth($symbol);
    }

    return $width;
  }

  /**
   * Splits text into ANSI-safe visible symbols.
   *
   * Each returned symbol preserves any currently active ANSI style so that
   * slices can be safely re-joined without losing coloring.
   *
   * @param string $text The text to split.
   * @return string[] The visible symbols.
   */
  public static function visibleSymbols(string $text): array
  {
    if ($text === '') {
      return [];
    }

    $text = self::formatStyles($text);

    // Plain ASCII is by far the common case (map rows, borders, menu text).
    // Splitting it bytewise skips the grapheme regex entirely, which is the
    // single hottest operation in the render path.
    if (! preg_match('/[^\x20-\x7E]/', $text)) {
      return $text === '' ? [] : str_split($text);
    }

    $symbols = [];
    $activeAnsi = '';
    preg_match_all('/\x1B\[[0-9;?]*[ -\/]*[@-~]|\X/u', $text, $matches);

    foreach ($matches[0] ?? [] as $token) {
      if (preg_match(self::ANSI_PATTERN, $token) === 1) {
        if (self::isResetSequence($token)) {
          $activeAnsi = '';
        } else {
          $activeAnsi .= $token;
        }
        continue;
      }

      if ($token === '') {
        continue;
      }

      $symbols[] = $activeAnsi !== ''
        ? $activeAnsi . $token . Color::RESET->value
        : $token;
    }

    return $symbols;
  }

  /**
   * Returns a symbol-based slice of the text.
   *
   * @param string $text The text to slice.
   * @param int $start The starting symbol index.
   * @param int|null $length The number of symbols to include.
   * @return string The sliced text.
   */
  public static function sliceSymbols(string $text, int $start, ?int $length = null): string
  {
    $symbols = self::visibleSymbols($text);
    $slice = array_slice($symbols, max(0, $start), $length);

    return implode('', $slice);
  }

  /**
   * Truncates text to the requested display width.
   *
   * @param string $text The text to truncate.
   * @param int $width The maximum display width.
   * @return string The truncated text.
   */
  public static function truncateToWidth(string $text, int $width): string
  {
    if ($width <= 0 || $text === '') {
      return '';
    }

    $visibleWidth = 0;
    $output = [];

    foreach (self::visibleSymbols($text) as $symbol) {
      $symbolWidth = self::getSymbolWidth($symbol);

      if ($visibleWidth + $symbolWidth > $width) {
        break;
      }

      $output[] = $symbol;
      $visibleWidth += $symbolWidth;
    }

    return implode('', $output);
  }

  /**
   * Right-pads text to the requested display width.
   *
   * @param string $text The text to pad.
   * @param int $width The target width.
   * @return string The padded text.
   */
  public static function padRight(string $text, int $width): string
  {
    $text = self::truncateToWidth($text, $width);
    $padding = max(0, $width - self::displayWidth($text));

    return $text . str_repeat(' ', $padding);
  }

  /**
   * Left-pads text to the requested display width.
   *
   * @param string $text The text to pad.
   * @param int $width The target width.
   * @return string The padded text.
   */
  public static function padLeft(string $text, int $width): string
  {
    $text = self::truncateToWidth($text, $width);
    $padding = max(0, $width - self::displayWidth($text));

    return str_repeat(' ', $padding) . $text;
  }

  /**
   * Centers text within the requested display width.
   *
   * @param string $text The text to pad.
   * @param int $width The target width.
   * @return string The padded text.
   */
  public static function padCenter(string $text, int $width): string
  {
    $text = self::truncateToWidth($text, $width);
    $padding = max(0, $width - self::displayWidth($text));
    $leftPadding = intdiv($padding, 2);
    $rightPadding = $padding - $leftPadding;

    return str_repeat(' ', $leftPadding) . $text . str_repeat(' ', $rightPadding);
  }

  /**
   * Fits text to the target width using the requested alignment.
   *
   * @param string $text The text to fit.
   * @param int $width The target width.
   * @param string $alignment The alignment: left, center, or right.
   * @return string The fitted text.
   */
  public static function fit(string $text, int $width, string $alignment = 'left'): string
  {
    return match ($alignment) {
      'right' => self::padLeft($text, $width),
      'center' => self::padCenter($text, $width),
      default => self::padRight($text, $width),
    };
  }

  /**
   * Measures the display width of a single visible symbol.
   *
   * @param string $symbol The symbol to measure.
   * @return int The display width in terminal cells.
   */
  private static function getSymbolWidth(string $symbol): int
  {
    // Rendering repeats the same handful of glyphs thousands of times per
    // frame, and measuring one costs several regex passes. Cache by symbol.
    static $widths = [];

    if (isset($widths[$symbol])) {
      return $widths[$symbol];
    }

    if (count($widths) > self::SYMBOL_CACHE_LIMIT) {
      $widths = [];
    }

    return $widths[$symbol] = self::measureSymbolWidth($symbol);
  }

  /**
   * Measures a symbol's column width.
   *
   * @param string $symbol The symbol to measure.
   * @return int The width in columns.
   */
  private static function measureSymbolWidth(string $symbol): int
  {
    $symbol = self::stabilizeSymbol(self::stripAnsi($symbol));

    if ($symbol === '') {
      return 0;
    }

    if (preg_match('/\x{200D}/u', $symbol) === 1) {
      return 2;
    }

    // Astral-plane pictographs render double-width everywhere. BMP
    // pictographs (⚔, ❤, ➡ …) default to narrow text presentation in
    // terminals, so they fall through to the mb_strwidth measurement.
    if (
      preg_match('/[\x{10000}-\x{10FFFF}]/u', $symbol) === 1 &&
      preg_match('/\p{Extended_Pictographic}/u', $symbol) === 1
    ) {
      return 2;
    }

    $baseSymbol = preg_replace('/[\p{Mn}\x{200D}\x{FE0E}\x{FE0F}]/u', '', $symbol) ?? $symbol;

    if ($baseSymbol === '') {
      return 0;
    }

    return max(1, min(2, mb_strwidth($baseSymbol, 'UTF-8')));
  }

  /**
   * Whether the project opted into composite (ZWJ) emoji.
   *
   * Answered by {@see TerminalCapabilities}, which detects the host
   * terminal at start-up: composing terminals keep their glyphs intact,
   * everything else gets the width-stable reduction.
   *
   * @return bool True when composite emoji are allowed.
   */
  protected static function allowsCompositeEmoji(): bool
  {
    return TerminalCapabilities::supportsCompositeEmoji();
  }

  /**
   * Rewrites every width-unstable grapheme in the text into its stable form.
   *
   * Safe for text containing ANSI styling: escape sequences carry none of the
   * rewritten code points, so they pass through untouched.
   *
   * @param string $text The text to stabilize.
   * @return string The stabilized text.
   */
  public static function stabilize(string $text): string
  {
    if ($text === '' || preg_match('/[\x{200D}\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', $text) !== 1) {
      return $text;
    }

    return preg_replace_callback(
      '/\X/u',
      static fn(array $match): string => self::stabilizeSymbol($match[0]),
      $text
    ) ?? $text;
  }

  /**
   * Rewrites a width-unstable grapheme into its terminal-stable form.
   *
   * Terminals disagree on how far these sequences advance the cursor, which
   * is the classic source of misaligned panel borders:
   *
   * - ZWJ sequences and skin-tone modifiers (e.g. "🏃🏽‍➡️") are reduced to
   *   their base glyph, which renders one predictable cell pair everywhere.
   * - A variation selector on a narrow BMP base (e.g. "⚔️" = U+2694 + VS16)
   *   is stripped, rendering the plain text glyph at one column everywhere.
   *   Astral emoji keep their selectors: they are reliably double-width.
   *
   * @param string $symbol The grapheme to stabilize.
   * @return string The stabilized grapheme.
   */
  public static function stabilizeSymbol(string $symbol): string
  {
    static $stabilized = [];

    if (isset($stabilized[$symbol])) {
      return $stabilized[$symbol];
    }

    if (count($stabilized) > self::SYMBOL_CACHE_LIMIT) {
      $stabilized = [];
    }

    return $stabilized[$symbol] = self::computeStabilizedSymbol($symbol);
  }

  /**
   * Computes the terminal-stable form of a grapheme.
   *
   * @param string $symbol The symbol to stabilize.
   * @return string The stabilized symbol.
   */
  private static function computeStabilizedSymbol(string $symbol): string
  {
    $visible = self::stripAnsi($symbol);

    if ($visible === '' || preg_match('/[\x{200D}\x{FE0E}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', $visible) !== 1) {
      return $symbol;
    }

    // ZWJ sequences and skin-tone modifiers: reduce to the base code point.
    // A project that opts into composite emoji keeps them intact, since
    // reducing them would collapse distinct sprites (the east-facing runner
    // and the plain runner) into the same glyph.
    if (preg_match('/[\x{200D}\x{1F3FB}-\x{1F3FF}]/u', $visible) === 1) {
      if (self::allowsCompositeEmoji()) {
        return $symbol;
      }

      $codepoints = preg_split('//u', $visible, -1, PREG_SPLIT_NO_EMPTY) ?: [];
      $base = $codepoints[0] ?? '';

      if ($base === '') {
        return $symbol;
      }

      // Keep a directly attached VS16 only on astral bases, where emoji
      // presentation is already the default width.
      if (($codepoints[1] ?? '') === "\u{FE0F}" && mb_ord($base, 'UTF-8') >= 0x10000) {
        $base .= "\u{FE0F}";
      }

      return str_replace($visible, $base, $symbol);
    }

    // Variation selector on a BMP base: astral emoji are stable as-is.
    if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $visible) === 1) {
      return $symbol;
    }

    $visibleBase = preg_replace('/[\x{FE0E}\x{FE0F}]/u', '', $visible) ?? $visible;

    if ($visibleBase === '' || mb_strwidth($visibleBase, 'UTF-8') > 1) {
      return $symbol;
    }

    // Drop the selector from the full symbol so any ANSI styling survives.
    return preg_replace('/[\x{FE0E}\x{FE0F}]/u', '', $symbol) ?? $symbol;
  }

  /**
   * Converts supported Symfony formatter tags to ANSI codes.
   *
   * @param string $text The text to normalize.
   * @return string The normalized text.
   */
  public static function formatStyles(string $text): string
  {
    if ($text === '' || ! str_contains($text, '<') || preg_match(self::FORMATTER_TAG_PATTERN, $text) !== 1) {
      return $text;
    }

    try {
      return self::getFormatter()->format($text);
    } catch (Throwable) {
      return $text;
    }
  }

  /**
   * Returns the shared Symfony output formatter instance.
   *
   * @return OutputFormatter The formatter.
   */
  private static function getFormatter(): OutputFormatter
  {
    return self::$formatter ??= new OutputFormatter(true);
  }

  /**
   * Determines whether an ANSI sequence resets the active style state.
   *
   * @param string $ansi The ANSI sequence.
   * @return bool True if the sequence resets formatting.
   */
  private static function isResetSequence(string $ansi): bool
  {
    if (!preg_match('/\x1B\[([0-9;]*)m/', $ansi, $matches)) {
      return false;
    }

    $parameters = $matches[1] === ''
      ? ['0']
      : array_filter(explode(';', $matches[1]), static fn(string $value): bool => $value !== '');

    foreach ($parameters as $parameter) {
      if (in_array((int)$parameter, [0, 22, 23, 24, 25, 27, 28, 29, 39, 49, 54, 55, 59], true)) {
        return true;
      }
    }

    return false;
  }
}
