<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Messaging\Storybook\StorybookOutcome;

/** A reader that reports its outcome until the event owner resets the presentation. */
interface EventStorybookOutcomePresentationInterface extends EventStorybookPresentationInterface
{
    public function getStorybookOutcome(): ?StorybookOutcome;
}
