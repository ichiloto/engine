<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** One measured reading line. Field labels appear only on a record's first line; values start at $column. */
final readonly class MenuDetailLine
{
  public function __construct(
    public int $offset,
    public string $text = '',
    public int $column = 0,
    public string $label = '',
    public string $color = 'text',
    public bool $isField = false,
    public bool $closesField = false,
    public ?int $entry = null,
  ) {}
}
