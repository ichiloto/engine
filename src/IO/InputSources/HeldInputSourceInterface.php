<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\IO\KeyTransition;

/**
 * A source that can know which controls are physically held.
 *
 * It still yields event-only keys through poll(), including OS repeats, so
 * menus and other edge consumers behave exactly as they do for any source.
 * Transitions are an additional, bounded stream processed before gameplay.
 */
interface HeldInputSourceInterface extends InputSourceInterface
{
  /** True only while the current session actually reports key releases. */
  public function canReportHeldState(): bool;

  /** @return list<KeyTransition> Transitions received since the previous call, in arrival order. */
  public function drainTransitions(): array;
}
