<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/** Width in existing Canvas text cells; shared by headings and record values. */
final readonly class MenuRowColumn
{
  public function __construct(public int $cells, public HorizontalAlignment $alignment = HorizontalAlignment::RIGHT)
  {
    if ($cells < 1 || $cells > 512) {
      throw new InvalidArgumentException('Menu value columns require 1..512 cells.');
    }
  }
}
