<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/**
 * The measured reading area a renderer pages a detail sheet into. Terminal counts rows (line height 1);
 * Canvas counts pixels with its cell height, so records taller than one line still page by whole lines.
 */
final readonly class MenuDetailViewport
{
  public function __construct(public int $columns, public int $height, public int $lineHeight = 1)
  {
    if ($columns < 1 || $height < 1 || $lineHeight < 1) {
      throw new InvalidArgumentException('A detail viewport needs positive columns, height and line height.');
    }
  }
}
