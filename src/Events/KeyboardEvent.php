<?php

namespace Ichiloto\Engine\Events;

use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\IO\Enumerations\KeyCode;

/**
 * KeyboardEvent is the base class for all keyboard events.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class KeyboardEvent extends Event
{
  /**
   * Constructs a new instance of the KeyboardEvent class.
   *
   * @param string $key The key that was pressed.
   * @param bool $ctrlKey Whether the control key was pressed.
   * @param bool $shiftKey Whether the shift key was pressed.
   * @param bool $altKey Whether the alt key was pressed.
   * @param bool $metaKey Whether the meta key was pressed.
   */
  public function __construct(
    private string $key,
    private bool   $ctrlKey = false,
    private bool   $shiftKey = false,
    private bool   $altKey = false,
    private bool   $metaKey = false,
  )
  {
    parent::__construct(EventType::KEYBOARD);
  }

  /**
   * Returns the key that was pressed.
   *
   * @return KeyCode|null The key that was pressed.
   */
  public function getKey(): ?KeyCode
  {
    return KeyCode::tryFrom($this->key);
  }

  /**
   * Returns a boolean value indicating if the `Ctrl` key was pressed when the event was created.
   *
   * @return bool A boolean value indicating if the `Ctrl` key was pressed when the event was created.
   */
  public function ctrlIsPressed(): bool
  {
    return $this->ctrlKey;
  }

  /**
   * Returns a boolean value indicating if the `Shift` key was pressed when the event was created.
   *
   * @return bool A boolean value indicating if the `Shift` key was pressed when the event was created.
   */
  public function shiftIsPressed(): bool
  {
    return $this->shiftKey;
  }

  /**
   * Returns a boolean value indicating if the `Alt` key was pressed when the event was created.
   *
   * @return bool A boolean value indicating if the `Alt` key was pressed when the event was created.
   */
  public function altIsPressed(): bool
  {
    return $this->altKey;
  }

  /**
   * Returns a boolean value indicating if the `Meta` key was pressed when the event was created.
   *
   * @return bool A boolean value indicating if the `Meta` key was pressed when the event was created.
   */
  public function metaIsPressed(): bool
  {
    return $this->metaKey;
  }

  /**
   * Returns a boolean value indicating if a modifier key such as `Alt`, `Shift`, `Ctrl`, or `Meta`, was pressed when
   * the event was created.
   *
   * @return bool A boolean value indicating if a modifier key such as `Alt`, `Shift`, `Ctrl`, or `Meta`, was pressed
   */
  public function hasModifier(): bool
  {
    return ($this->ctrlIsPressed() || $this->shiftIsPressed() || $this->altIsPressed() || $this->metaIsPressed());
  }
}