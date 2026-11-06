<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/** A destination's immutable world, compiled cooperatively on its caller's thread. */
final class PresentationWorldPreparation
{
    private ?Fiber $work;
    private int $startedAt = 0;
    private int $budget = 0;

    /**
     * @param Closure(Closure(): void): PresentationWorld $compile
     * @param Closure(): bool $isDisplayPolicyCurrent
     */
    public function __construct(Closure $compile, private readonly Closure $isDisplayPolicyCurrent)
    {
        $this->work = new Fiber(fn(): PresentationWorld => $compile($this->checkpoint(...)));
    }

    /** Advance through complete work units. Null means the caller can resume next tick. */
    public function advance(int $budgetNanoseconds): ?PresentationWorld
    {
        if ($budgetNanoseconds < 1) {
            throw new InvalidArgumentException('World preparation needs a positive time budget.');
        }

        $work = $this->work ?? throw new LogicException('World preparation was cancelled.');
        if ($work->isTerminated()) {
            return $work->getReturn();
        }
        if (!($this->isDisplayPolicyCurrent)()) {
            $this->cancel();
            throw new RuntimeException('The display policy changed during retained world preparation.');
        }

        $this->startedAt = hrtime(true);
        $this->budget = $budgetNanoseconds;
        if ($work->isStarted()) {
            $work->resume();
        } else {
            $work->start();
        }
        return $work->isTerminated() ? $work->getReturn() : null;
    }

    /** Release any partial buffers without installing or caching a world. */
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
}
