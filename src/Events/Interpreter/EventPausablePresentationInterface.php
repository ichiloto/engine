<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

/** The host owns input while the retained presentation freezes its own clocks. */
interface EventPausablePresentationInterface extends EventPresentationInterface
{
    public function pausePlayback(float $now): void;
    public function resumePlayback(float $now): void;
}
