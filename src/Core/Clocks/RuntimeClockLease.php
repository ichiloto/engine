<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Clocks;

use Closure;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** The host retains this lease until its timed session/resources have been released. */
final class RuntimeClockLease
{
    private bool $closed = false;

    /** @param Closure(): void $release */
    public function __construct(private readonly Closure $release) {}

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        ($this->release)();
    }

    public function __destruct()
    {
        try { $this->close(); }
        catch (Throwable $error) { Debug::warn('Runtime clock cleanup failed: ' . $error->getMessage()); }
    }
}
