<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Storybook;

/** How the shared storybook reader ended. */
enum StorybookOutcome
{
    case FINISHED;
    case CANCELLED;
}
