<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\ProjectState;

use Closure;
use LogicException;

/** Owns a restored snapshot until it is committed or explicitly released. */
final class ProjectStateScope
{
    /** @param Closure(bool): void $complete True restores the prior snapshot; false retains this one. */
    public function __construct(private ?Closure $complete) {}

    private function __clone() {}

    public bool $isActive { get => $this->complete !== null; }

    /** Keep the successfully installed campaign state. */
    public function commit(): void
    {
        $complete = $this->complete ?? throw new LogicException('This project-state scope is already complete.');
        $complete(false);
        $this->complete = null;
    }

    /** Restore the exact prior state, including a previously unstarted registry. */
    public function release(): void
    {
        if ($this->complete === null) { return; }
        ($this->complete)(true);
        $this->complete = null;
    }
}
