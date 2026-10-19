<?php

namespace Tests\Support\Input;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\HeldInputSourceInterface;
use Ichiloto\Engine\IO\KeyTransition;

/** A source that reports releases, fed one input update at a time. */
final class FakeHeldInputSource implements HeldInputSourceInterface
{
  /** @var list<?KeyCode> Event-only keys for later polls. */
  public array $keys = [];
  /** @var list<KeyTransition> Transitions for the next update. */
  public array $transitions = [];
  public bool $reportsHeldState = true;

  public function press(string $control, KeyCode $key): self
  {
    $this->keys[] = $key;
    $this->transitions[] = KeyTransition::press($control, $key);
    return $this;
  }

  public function release(string $control): self
  {
    $this->transitions[] = KeyTransition::release($control);
    return $this;
  }

  public function poll(): ?KeyCode
  {
    return array_shift($this->keys);
  }

  public function reset(bool $drainBufferedInput = false): void
  {
    $this->keys = $this->transitions = [];
  }

  public function canReportHeldState(): bool
  {
    return $this->reportsHeldState;
  }

  public function drainTransitions(): array
  {
    $transitions = $this->transitions;
    $this->transitions = [];
    return $transitions;
  }
}
