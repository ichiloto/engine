<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;
use Ichiloto\Engine\UI\Modal\StorybookReadingCapacity;

/** A graphical host measures its actual prose space before the shared reader opens. */
interface StorybookReadingCapacityProviderInterface
{
    public function getStorybookReadingCapacity(StorybookDefinition $book): StorybookReadingCapacity;
}
