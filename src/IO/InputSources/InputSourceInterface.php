<?php

namespace Ichiloto\Engine\IO\InputSources;

use Ichiloto\Engine\IO\Enumerations\KeyCode;

interface InputSourceInterface
{
  /** Return one normalized key, or null. Never interpret bindings or game actions. */
  public function poll(): ?KeyCode;

  /** Clear cached input; optionally drain currently available upstream input. */
  public function reset(bool $drainBufferedInput = false): void;
}
