<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use InvalidArgumentException;

/** Reading space supplied by a projection, excluding its header and controls. */
final readonly class StorybookReadingCapacity
{
    public function __construct(public int $columns, public int $rows)
    {
        if ($columns < 1 || $rows < 1) {
            throw new InvalidArgumentException('Storybook reading capacity requires positive columns and rows.');
        }
    }
}
