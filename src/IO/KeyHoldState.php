<?php

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Enumerations\KeyTransitionType;

/**
 * Held controls and this update's press edges, for sources that report releases.
 *
 * Each press gets a monotonically increasing order so consumers can tell
 * which held control was pressed most recently. A control is held under the
 * key code it typed when pressed; its release is matched by control identity,
 * so a modifier change between press and release never strands it. Press
 * edges survive a release within the same update, preserving quick taps.
 */
final class KeyHoldState
{
  /** Keyboard rollover is far below this; a flood cannot grow the state unboundedly. */
  public const int MAX_HELD_CONTROLS = 64;

  /** @var array<string, array{key: KeyCode, order: int}> Keyed by control identity. */
  private array $held = [];
  /** @var list<array{key: KeyCode, order: int}> Presses applied since beginUpdate(). */
  private array $pressed = [];
  private int $order = 0;

  /** Start a new input update: previous press edges are no longer edges. */
  public function beginUpdate(): void
  {
    $this->pressed = [];
  }

  public function apply(KeyTransition $transition): void
  {
    switch ($transition->type) {
      case KeyTransitionType::PRESS:
        if (isset($this->held[$transition->control]) || count($this->held) >= self::MAX_HELD_CONTROLS) {
          return;
        }
        $entry = ['key' => $transition->key, 'order' => ++$this->order];
        $this->held[$transition->control] = $entry;
        $this->pressed[] = $entry;
        return;
      case KeyTransitionType::RELEASE:
        unset($this->held[$transition->control]);
        return;
      case KeyTransitionType::RESET:
        $this->clear();
    }
  }

  /** Forget held controls and edges. Press order stays monotonic across clears. */
  public function clear(): void
  {
    $this->held = [];
    $this->pressed = [];
  }

  /** Consume only this update's press edges; controls stay held. */
  public function consumePresses(): void
  {
    $this->pressed = [];
  }

  public function isKeyHeld(KeyCode $key): bool
  {
    return array_any($this->held, static fn(array $entry): bool => $entry['key'] === $key);
  }

  public function wasKeyPressed(KeyCode $key): bool
  {
    return array_any($this->pressed, static fn(array $entry): bool => $entry['key'] === $key);
  }

  /** The latest press order of this key among held controls and this update's presses. */
  public function getKeyPressOrder(KeyCode $key): ?int
  {
    $order = null;
    foreach ([...array_values($this->held), ...$this->pressed] as $entry) {
      if ($entry['key'] === $key) {
        $order = max($order ?? 0, $entry['order']);
      }
    }
    return $order;
  }

  /** The order of the most recent press ever applied; zero before the first. */
  public function getLatestPressOrder(): int
  {
    return $this->order;
  }
}
