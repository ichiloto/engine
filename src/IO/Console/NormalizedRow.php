<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Diagnostics\LatencyTrace;

/**
 * Immutable terminal cells with separate logical-symbol offsets. Styled anchors
 * retain their self-contained SGR/control prefix; NUL is only a continuation.
 * No row is serialized in order to select or compose its cells.
 * @internal
 */
final readonly class NormalizedRow
{
  public const string CONTINUATION = "\0";

  /** @param list<string> $cells @param list<int> $offsets */
  private function __construct(public array $cells, private array $offsets)
  {
  }

  public static function fromText(string $text, ?int $width = null): self
  {
    // String authors retain the established fitting/control-boundary contract.
    // Retained map rows bypass this compatibility boundary entirely.
    if ($width !== null) { $text = TerminalText::truncateToWidth($text, $width); }
    return self::fromSymbols(TerminalText::visibleSymbols($text));
  }

  /** @param list<string> $symbols Already separated authoring symbols, not a row string. */
  public static function fromSymbols(array $symbols): self
  {
    $started = LatencyTrace::getTimeNow();
    $cells = $offsets = [];
    foreach ($symbols as $symbol) {
      $offsets[] = count($cells);
      $symbol = TerminalText::stabilizeSymbol($symbol);
      // Preserve grapheme boundaries even where a reset left no active style.
      // Otherwise serialization could turn separate indicators/ZWJ pieces into
      // a new glyph with a different width. ASCII payloads remain unchanged.
      if (!str_contains($symbol, "\e")
        && preg_match('/\A(?:[\p{M}\x{200D}]|[\x{1F1E6}-\x{1F1FF}]\z)|\x{200D}\z/u', $symbol) === 1) {
        $symbol = "\e[0m" . $symbol . "\e[0m";
      }
      $symbol = str_replace("\0", '?', $symbol);
      $cells[] = $symbol;
      for ($i = 1, $width = self::symbolWidth($symbol); $i < $width; $i++) {
        $cells[] = self::CONTINUATION;
      }
    }
    LatencyTrace::end('terminal.normalize', $started, ['symbols' => count($symbols), 'cells' => count($cells)]);
    return new self($cells, $offsets);
  }

  /**
   * Map cells of exactly $columns display columns each. A cell may hold several
   * symbols but is one logical offset; a narrower cell is padded with spaces,
   * so selecting cells always selects whole, aligned columns.
   *
   * @param list<string> $cells
   */
  public static function fromCells(array $cells, int $columns): self
  {
    $symbols = $starts = [];
    foreach ($cells as $cell) {
      $starts[] = count($symbols);
      $width = 0;
      foreach (TerminalText::visibleSymbols($cell) as $symbol) {
        $symbols[] = $symbol;
        $width += self::symbolWidth($symbol);
      }
      for (; $width < $columns; $width++) { $symbols[] = ' '; }
    }
    $row = self::fromSymbols($symbols);
    return new self($row->cells, array_map(static fn(int $start): int => $row->offsets[$start], $starts));
  }

  /** Authored zero-width components still own a cell at a control boundary. */
  public static function symbolWidth(string $symbol): int
  {
    return max(1, TerminalText::getSymbolWidth($symbol));
  }

  /** Select logical map symbols, then fit complete glyphs into display columns. */
  public function select(int $start, int $length, int $width, bool $pad = true): self
  {
    $start = max(0, $start);
    $first = $this->offsets[$start] ?? count($this->cells);
    $last = $this->offsets[$start + max(0, $length)] ?? count($this->cells);
    $end = min($last, $first + max(0, $width));
    while ($end > $first && ($this->cells[$end] ?? '') === self::CONTINUATION) { $end--; }
    $cells = array_slice($this->cells, $first, $end - $first);
    return new self($pad ? array_pad($cells, max(0, $width), ' ') : $cells, []);
  }

  /** Select existing display cells without re-normalizing their glyphs or styles. */
  public function selectColumns(int $start, int $width): self
  {
    $start = max(0, $start);
    if (($this->cells[$start] ?? null) === self::CONTINUATION) {
      throw new \InvalidArgumentException('A normalized column selection must begin at a glyph anchor.');
    }
    $end = min(count($this->cells), $start + max(0, $width));
    while ($end > $start && ($this->cells[$end] ?? '') === self::CONTINUATION) { $end--; }
    return new self(array_slice($this->cells, $start, max(0, $end - $start)), []);
  }

  /** @return list<string> A clipped write never paints half a wide glyph. */
  public function clippedCells(int $width): array
  {
    $end = min(count($this->cells), max(0, $width));
    while ($end > 0 && ($this->cells[$end] ?? '') === self::CONTINUATION) { $end--; }
    return array_slice($this->cells, 0, $end);
  }
}
