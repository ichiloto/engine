<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;

/** Logical-pixel geometry supported by the protocol v1 renderer. */
final readonly class RendererGridConfig
{
  public function __construct(
    public int $columns = 80,
    public int $rows = 24,
    public int $cellWidth = 16,
    public int $cellHeight = 24,
  )
  {
    if ($columns < 1 || $columns > 512 || $rows < 1 || $rows > 256
      || $cellWidth < 1 || $cellWidth > 256 || $cellHeight < 1 || $cellHeight > 256
      || $columns * $cellWidth > 16384 || $rows * $cellHeight > 16384) {
      throw new InvalidArgumentException('Grid geometry exceeds protocol v1 limits.');
    }
  }

  /** @return array{columns: int, rows: int, cellWidth: int, cellHeight: int} */
  public function toArray(): array
  {
    return get_object_vars($this);
  }
}
