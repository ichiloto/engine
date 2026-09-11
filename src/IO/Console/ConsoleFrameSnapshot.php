<?php

namespace Ichiloto\Engine\IO\Console;

use InvalidArgumentException;

/** Immutable plain-text grid: one Unicode scalar per logical cell, not terminal width. */
final readonly class ConsoleFrameSnapshot
{
  /** @var list<string> */
  public array $rows;

  /** @param array<array-key, mixed> $rows Validated into a rectangular list of scalar rows. */
  public function __construct(
    public int $width,
    public int $height,
    array $rows,
  )
  {
    if ($width < 1 || $height < 1 || ! array_is_list($rows) || count($rows) !== $height) {
      throw new InvalidArgumentException('Console snapshot requires positive dimensions and exactly height rows.');
    }
    $copy = [];
    foreach ($rows as $row) {
      if (! is_string($row) || preg_match('//u', $row) !== 1 || preg_match('/\p{Cc}/u', $row) === 1
        || mb_strlen($row, 'UTF-8') !== $width) {
        throw new InvalidArgumentException('Console snapshot rows must contain exactly width UTF-8 scalars without controls.');
      }
      $copy[] = $row;
    }
    $this->rows = $copy;
  }
}
