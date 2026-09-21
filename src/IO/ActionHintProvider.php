<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

/** Presentation only. Implementations neither dispatch actions nor emulate keyboard input. */
interface ActionHintProvider
{
  public function controlForAction(string $action): ?ControlHint;
}
