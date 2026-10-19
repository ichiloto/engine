<?php

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Enumerations\KeyTransitionType;
use InvalidArgumentException;

/**
 * One normalized transition from a source that knows physical key state.
 *
 * A control identity names the physical key independently of the text it
 * typed: releasing a key after Shift changed its case releases the key that
 * was pressed. Only a press carries the key code bindings match.
 */
final readonly class KeyTransition
{
  private function __construct(
    public KeyTransitionType $type,
    public ?string $control = null,
    public ?KeyCode $key = null,
  )
  {
    if ($type !== KeyTransitionType::RESET && ($control === null || $control === '')) {
      throw new InvalidArgumentException('A key press or release requires a control identity.');
    }
  }

  public static function press(string $control, KeyCode $key): self
  {
    return new self(KeyTransitionType::PRESS, $control, $key);
  }

  public static function release(string $control): self
  {
    return new self(KeyTransitionType::RELEASE, $control);
  }

  public static function reset(): self
  {
    return new self(KeyTransitionType::RESET);
  }
}
