<?php

namespace Ichiloto\Engine\IO\InputSources;

/** Optional native metadata for the exact key returned by the latest poll, independent of held-state batches. */
interface PressAwareInputSourceInterface extends InputSourceInterface
{
  /** True for a fresh press, false for a repeat or an invalidated press, null when unavailable. */
  public function wasLastPollPress(): ?bool;
}
