<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * One measured reading line. Field labels appear only on a record's first line; values start at $column.
 * An icon-list line lists its items: an icon role drawn in its cells, or text such as the icon's name.
 * In a column arrangement a line sits in band column $band, $origin cells from the sheet's left, $span cells wide.
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
    public bool $isRule = false,
    public int $origin = 0,
    public ?int $band = null,
    public ?int $span = null,
  ) {}

  public function withPlacement(int $origin, ?int $band, ?int $span): self
  {
    return new self($this->offset, $this->text, $this->column, $this->label, $this->color, $this->isField,
      $this->closesField, $this->entry, $this->isHeading, $this->items, $this->isRule, $origin, $band, $span);
  }
}
