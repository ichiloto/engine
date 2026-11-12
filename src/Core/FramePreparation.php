<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * A result assembled cooperatively on its caller's thread, with no owned scene changes.
 * @template T of object
 */
class FramePreparation
{
    private ?Fiber $work;
    private int $startedAt = 0;
    private int $budget = 0;
    private bool $canPublish = false;

    /**
     * @param Closure(Closure(): void, Closure(): void): T $compile
     * @param (Closure(): bool)|null $isCurrent
     */
    public function __construct(Closure $compile, private readonly ?Closure $isCurrent = null)
    {
        $this->work = new Fiber(fn(): object => $compile($this->checkpoint(...), $this->awaitPublication(...)));
    }

    /** Advance through complete work units. Null means the caller can resume next tick.
     * @return T|null
     */
    public function advance(int $budgetNanoseconds, bool $canPublish = true): ?object
    {
        if ($budgetNanoseconds < 1) {
            throw new InvalidArgumentException('Frame preparation needs a positive time budget.');
        }

        $work = $this->work ?? throw new LogicException('Frame preparation was cancelled.');
        if ($work->isTerminated()) {
            return $work->getReturn();
        }
        if ($this->isCurrent !== null && !($this->isCurrent)()) {
            $this->cancel();
            throw new RuntimeException('The input policy changed during frame preparation.');
        }

        $this->startedAt = hrtime(true);
        $this->budget = $budgetNanoseconds;
        $this->canPublish = $canPublish;
        if ($work->isStarted()) {
            $work->resume();
        } else {
            $work->start();
        }
        return $work->isTerminated() ? $work->getReturn() : null;
    }

    /** Release any partial buffers before the caller receives a result. */
    public function cancel(): void
    {
        $this->work = null;
    }

    private function checkpoint(): void
    {
        if (hrtime(true) - $this->startedAt >= $this->budget) {
            Fiber::suspend();
        }
    }

    /** Hold final validation for the update that can use the result. */
    private function awaitPublication(): void
    {
        while (!$this->canPublish) {
            Fiber::suspend();
        }
    }
}
