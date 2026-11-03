<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\UI\Modal\StorybookReadingCapacity;

/**
 * A presentation host measures its actual graphical prose space before the shared reader opens.
 * Null states that no graphical projection draws the reader on this host now (a Terminal session, or a project
 * whose scene owners keep their Terminal cells), so the Terminal reader alone sets the capacity.
 */
interface StorybookReadingCapacityProviderInterface
{
    public function getStorybookReadingCapacity(StorybookDefinition $book): ?StorybookReadingCapacity;
}
