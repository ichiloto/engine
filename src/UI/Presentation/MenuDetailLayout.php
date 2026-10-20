<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextViewport;

/** A detail sheet measured for one width. Line offsets anchor the reading position in the sheet's source text. */
final readonly class MenuDetailLayout
{
  /** @param non-empty-list<MenuDetailLine> $lines */
  public function __construct(public string $source, public int $columns, public array $lines, public MenuDetailStyle $style) {}

  /** Each line's height and where its text starts inside it, in the viewport's unit. A field record shorter than
   * the style's minimum grows to it with its text centred; prose and blank lines keep one line height.
   * @return non-empty-list<array{height: int, top: int}>
   */
  public function measureLines(int $lineHeight): array
  {
    $metrics = array_fill(0, count($this->lines), ['height' => $lineHeight, 'top' => 0]);
    for ($first = 0; $first < count($this->lines); $first = $last + 1) {
      $last = $first;
      if (!$this->lines[$first]->isField) { continue; }
      while (!$this->lines[$last]->closesField) { $last++; }
      $extra = max(0, $this->style->recordMinHeight - ($last - $first + 1) * $lineHeight);
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
}
