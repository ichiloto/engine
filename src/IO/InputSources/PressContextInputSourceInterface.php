<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\IO\Enumerations\KeyCode;

/** A source that reports which keys were held when each discrete input arrived. */
interface PressContextInputSourceInterface extends PressAwareInputSourceInterface
{
  /** False for a repeated key. Null when the source cannot report press-time state. */
  public function wasKeyHeldAtLastPress(KeyCode $key): ?bool;
}
