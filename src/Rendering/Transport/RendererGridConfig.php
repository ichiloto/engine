<?php

namespace Ichiloto\Engine\Rendering\Transport;

use InvalidArgumentException;

/** Session text-grid geometry, independent of the presentation encoding. */
final readonly class RendererGridConfig
{
  public const int MAX_COLUMNS = 512;
  public const int MAX_ROWS = 256;
  public const int MAX_CELL_SIZE = 256;
  public const int MAX_EXTENT = 16384;

  public function __construct(
    public int $columns = 80,
    public int $rows = 24,
    public int $cellWidth = 16,
    public int $cellHeight = 24,
  )
  {
    if ($columns < 1 || $columns > self::MAX_COLUMNS || $rows < 1 || $rows > self::MAX_ROWS
      || $cellWidth < 1 || $cellWidth > self::MAX_CELL_SIZE || $cellHeight < 1 || $cellHeight > self::MAX_CELL_SIZE
      || $columns * $cellWidth > self::MAX_EXTENT || $rows * $cellHeight > self::MAX_EXTENT) {
      throw new InvalidArgumentException('Grid geometry exceeds renderer session limits.');
    }
  }

  /** @return array{columns: int, rows: int, cellWidth: int, cellHeight: int} */
  public function toArray(): array
  {
    return get_object_vars($this);
  }
}
