<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TextViewport;

/** A detail sheet measured for one width. Line offsets anchor the reading position in the sheet's source text. */
final readonly class MenuDetailLayout
{
  /** @param non-empty-list<MenuDetailLine> $lines */
  public function __construct(public string $source, public int $columns, public array $lines, public MenuDetailStyle $style) {}

  /** Plain lines for Terminal output and for paging through TextViewport.
   * @return non-empty-list<array{int, string}>
   */
  public function getWrappedLines(): array
  {
    return array_map(static fn(MenuDetailLine $line): array => [$line->offset, $line->text === '' ? $line->label
      : $line->label . str_repeat(' ', max(0, $line->column - TextViewport::measure($line->label))) . $line->text], $this->lines);
  }
}
