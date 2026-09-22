<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Text;

/** Measured reading window. No input or progress operations. */
final readonly class TextPage
{
  /** @param list<string> $lines */
  public function __construct(
    public string $source,
    public array $lines,
    public int $first,
    public int $total,
    public int $columns,
    public int $rows,
    public int $previousOffset,
    public int $nextOffset,
  ) {}

  public function range(): string
  {
    return sprintf('Lines %d-%d / %d', $this->first + 1, $this->first + count($this->lines), $this->total);
  }
}
