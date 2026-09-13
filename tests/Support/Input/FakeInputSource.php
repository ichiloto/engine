<?php

namespace Tests\Support\Input;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;

final class FakeInputSource implements InputSourceInterface
{
  /** @var list<?KeyCode> Future poll samples, like bytes not yet read from a stream. */
  public array $keys;
  /** @var list<bool> */
  public array $resets = [];

  public function __construct(?KeyCode ...$keys)
  {
    $this->keys = $keys;
  }

  public function poll(): ?KeyCode
  {
    return array_shift($this->keys);
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    $this->resets[] = $drainBufferedInput;
    if ($drainBufferedInput) {
      $this->keys = [];
    }
  }
}
