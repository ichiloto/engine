<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;

/** A detail sheet measured for one width. Line offsets anchor the reading position in the sheet's source text. */
final readonly class MenuDetailLayout
{
  /** Terminal heading strip background (black). */
  private const string TERMINAL_HEADING = "\033[40m";

  /** @param non-empty-list<MenuDetailLine> $lines */
  public function __construct(public string $source, public int $columns, public array $lines, public MenuDetailStyle $style,
    public bool $isColumnar = false) {}

  /** Each line's height and where its text starts inside it, in the viewport's unit. A field record or heading
   * shorter than the style's minimum grows to it with its text centred; prose, rules and blank lines keep one line.
   * @return non-empty-list<array{height: int, top: int}>
   */
  public function measureLines(int $lineHeight): array
  {
    return self::measure($this->lines, $this->style, $lineHeight);
  }

  /**
   * @param list<MenuDetailLine> $lines
   * @return list<array{height: int, top: int}>
   */
  public static function measure(array $lines, MenuDetailStyle $style, int $lineHeight): array
  {
    $metrics = array_fill(0, count($lines), ['height' => $lineHeight, 'top' => 0]);
    for ($first = 0; $first < count($lines); $first = $last + 1) {
      $last = $first;
      $line = $lines[$first];
      if ((!$line->isField && !$line->isHeading) || $line->isRule) { continue; }
      while ($line->isField ? !$lines[$last]->closesField
        : ($lines[$last + 1] ?? null)?->entry === $line->entry && $lines[$last + 1]->isHeading) { $last++; }
      $minimum = $line->isField ? $style->recordMinHeight : $style->headingMinHeight;
      $extra = max(0, $minimum - ($last - $first + 1) * $lineHeight);
      $top = intdiv($extra, 2);
      $metrics[$first] = ['height' => $metrics[$first]['height'] + $top, 'top' => $top];
      $metrics[$last]['height'] += $extra - $top;
    }
    return $metrics;
  }

  /** Plain lines for Terminal output and for paging through TextViewport.
   * @return non-empty-list<array{int, string}>
   */
  public function getWrappedLines(): array
  {
    return array_map(static fn(MenuDetailLine $line): array => [$line->offset, $line->text === '' ? $line->label
      : $line->label . str_repeat(' ', max(0, $line->column - TextViewport::measure($line->label))) . $line->text], $this->lines);
  }

  /**
   * A Terminal page's lines as the panel shows them: headings as an accent strip, record rules dimmed.
   * @return list<string>
   */
  public function getTerminalLines(TextPage $page, int $width): array
  {
    $lines = array_slice($this->lines, $page->first, count($page->lines));
    return array_map(static fn(MenuDetailLine $line, string $text): string => match (true) {
      $line->isHeading => self::TERMINAL_HEADING . Color::YELLOW->value . TerminalText::padRight($text, $width) . Color::RESET->value,
      $line->isRule => Color::apply($text, Color::DARK_GRAY),
      default => $text,
    }, $lines, $page->lines);
  }
}
