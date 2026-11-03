<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Messaging\Storybook\StorybookDefinition;

/** Cooperative read-only book presentation under the existing event owner. */
interface EventStorybookPresentationInterface extends EventPresentationInterface
{
    public function beginStorybook(StorybookDefinition $book): void;
}
