<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * One measured reading line. Field labels appear only on a record's first line; values start at $column.
 * An icon-list line lists its items: an icon role drawn in its cells, or the name text where no icon is drawn.
 */
final readonly class MenuDetailLine
{
  /** @param list<array{column: int, role: ?string, text: string}> $items */
  public function __construct(
    public int $offset,
    public string $text = '',
    public int $column = 0,
    public string $label = '',
    public string $color = 'text',
    public bool $isField = false,
    public bool $closesField = false,
    public ?int $entry = null,
    public bool $isHeading = false,
    public array $items = [],
  ) {}
}
