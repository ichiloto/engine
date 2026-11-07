<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game;

use Closure;
use Ichiloto\Engine\Core\FramePreparation;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\PreparedMap;
use Throwable;

/** The scene's one pending transfer and the callbacks owned by its origin. */
final class PendingMapTransfer
{
      public ?Closure $afterPreparation = null;
    public private(set) ?PreparedMap $destination = null;
    public private(set) ?Throwable $failure = null;

    /** @param FramePreparation<PreparedMap> $preparation */
    public function __construct(
        public readonly FramePreparation $preparation,
        public readonly Location $location,
        public readonly bool $useConfiguredTransition,
        public readonly ?Closure $onCommitted,
        public readonly ?Closure $onFailure,
        public readonly ?Closure $isCurrent,
    ) {}

    /** Compile the destination without installing it or invoking the transfer's callbacks. */
    public function advancePreparation(int $budget, bool $canPublish = true): void
    {
        if ($this->destination !== null || $this->failure !== null) { return; }
        try {
            $this->destination = $this->preparation->advance($budget, $canPublish);
        } catch (Throwable $error) {
            $this->failure = $error;
        }
    }
}
