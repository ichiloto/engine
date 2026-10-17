<?php

namespace Ichiloto\Engine\IO\InputSources;

/** Optional native metadata for the exact key returned by the latest poll, independent of held-state batches. */
interface PressAwareInputSourceInterface extends InputSourceInterface
{
  /** True for a fresh press, false for an OS repeat, null when this sample has no such metadata. */
  public function wasLastPollPress(): ?bool;
}
