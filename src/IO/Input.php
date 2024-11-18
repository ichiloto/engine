<?php

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;

/**
 * Input class. This class provides methods for getting input from the user.
 *
 * @package Ichiloto\Engine\IO
 */
class Input
{
  /**
   * Returns the value of the virtual axis identified by axisName.
   *
   * @param AxisName $axisName
   * @return float
   */
  public static function getAxis(AxisName $axisName): float
  {
    return InputManager::getAxis($axisName);
  }

  /**
   * Checks if the given key is pressed.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is pressed, false otherwise.
   */
  public static function isKeyPressed(KeyCode $keyCode): bool
  {
    return InputManager::isKeyPressed($keyCode);
  }

  /**
   * Checks if all the given keys are pressed.
   *
   * @param array<KeyCode> $keyCodes The key codes to check.
   * @return bool Returns true if any key is pressed, false otherwise.
   */
  public static function areAllKeysPressed(array $keyCodes): bool
  {
    return InputManager::areAllKeysPressed($keyCodes);
  }

  /**
   * Checks if any of the given keys are pressed.
   *
   * @param array<KeyCode> $keyCodes
   * @return bool Returns true if any key is pressed, false otherwise.
   */
  public static function isAnyKeyPressed(array $keyCodes): bool
  {
    return InputManager::isAnyKeyPressed($keyCodes);
  }

  /**
   * Checks if the given key is released.
   *
   * @param array $keyCodes The key codes to check.
   * @return bool Returns true if any key is released, false otherwise.
   */
  public static function isAnyKeyReleased(array $keyCodes): bool
  {
    return InputManager::isAnyKeyReleased($keyCodes);
  }

  /**
   * Checks if the given key is down.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is down, false otherwise.
   */
  public static function isKeyDown(KeyCode $keyCode): bool
  {
    return InputManager::isKeyDown($keyCode);
  }

  /**
   * Checks if the given key is up.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is up, false otherwise.
   */
  public static function isKeyUp(KeyCode $keyCode): bool
  {
    return InputManager::isKeyUp($keyCode);
  }
}