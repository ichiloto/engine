<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Closure;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** One owner's lease on an audio pause; nested owners release independently. */
final class AudioPlaybackPause
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
        catch (Throwable $error) { Debug::warn('Audio pause cleanup failed: ' . $error->getMessage()); }
    }
}
