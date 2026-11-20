<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Closure;
use Ichiloto\Engine\Events\Interpreter\EventExecutionLane;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;

/** Owns a cooperative phase body and releases its framing on every exit. */
final class CinematicFramingOperation implements EventPendingOperationInterface
{
    private bool $released = false;

    public function __construct(public readonly EventExecutionLane $body,
        private readonly Closure $advanceBody, private readonly Closure $releaseScope) {}

    public function update(float $deltaSeconds): bool
    {
        if ($this->released) { return true; }
        try {
            ($this->advanceBody)($this->body, $deltaSeconds);
            if ($this->body->status === EventExecutionStatus::FAILED) {
                throw new \RuntimeException($this->body->failureMessage ?? 'A cinematic framing phase failed.');
            }
            if ($this->body->status !== EventExecutionStatus::COMPLETED) { return false; }
            $this->release();
            return true;
        } catch (\Throwable $error) {
            try { $this->cancel(); }
            catch (\Throwable $cleanup) {
                throw new \RuntimeException($error->getMessage() . '; framing cleanup failed: ' . $cleanup->getMessage(), previous: $error);
            }
            throw $error;
        }
    }

    public function cancel(): void
    {
        if ($this->released) { return; }
        try { $this->body->cancel(); }
        finally { $this->release(); }
    }

    private function release(): void
    {
        if ($this->released) { return; }
        $this->released = true;
        ($this->releaseScope)();
    }
}
