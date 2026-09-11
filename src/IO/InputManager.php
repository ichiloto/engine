<?php

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\KeyboardEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use RuntimeException;

class InputManager
{
  /**
   * The previous key press.
   *
   * @var KeyCode|null
   */
  private static ?KeyCode $previousKeyPress = null;

  /**
   * The current key press.
   *
   * @var KeyCode|null
   */
  private static ?KeyCode $keyPress = null;

  /**
   * Terminal input remains the default unless a caller explicitly installs a source.
   */
  private static ?InputSourceInterface $inputSource = null;
  /**
   * @var EventManager|null The event manager.
   */
  private static ?EventManager $eventManager = null;
  /**
   * @var array $config The configuration.
   */
  protected static array $config = [];
  /**
   * @var array The bindings as the project authored them, captured at boot so
   * a player who rebinds their way into a corner can get back out.
   */
  protected static array $defaultConfig = [];

  /**
   * Initializes the InputManager.
   *
   * @param Game $game The instance of the game
   * @return void
   */
  public static function init(Game $game): void
  {
    self::$eventManager = EventManager::getInstance($game);
    self::resetState();
    $inputConfig = ConfigStore::get(InputConfig::class);
    assert($inputConfig instanceof InputConfig);
    self::$config = $inputConfig->all();
    self::$defaultConfig = self::$config;
  }

  /**
   * Returns the current bindings.
   *
   * @return array<string, array{description?: string, keys?: KeyCode[]}> The bindings, keyed by action.
   */
  public static function getBindings(): array
  {
    return self::$config;
  }

  /**
   * Returns the bindings the project shipped with.
   *
   * @return array<string, array{description?: string, keys?: KeyCode[]}> The default bindings.
   */
  public static function getDefaultBindings(): array
  {
    return self::$defaultConfig;
  }

  /**
   * Rebinds an action, taking effect immediately.
   *
   * @param string $action The action to rebind.
   * @param KeyCode[] $keys The keys to bind to it.
   * @return bool True when the action exists and was rebound.
   */
  public static function setBinding(string $action, array $keys): bool
  {
    if (! isset(self::$config[$action])) {
      return false;
    }

    self::$config[$action]['keys'] = array_values($keys);

    return true;
  }

  /**
   * Replaces every binding, taking effect immediately.
   *
   * @param array<string, array{description?: string, keys?: KeyCode[]}> $bindings The bindings to apply.
   * @return void
   */
  public static function setBindings(array $bindings): void
  {
    self::$config = $bindings;
  }

  /**
   * Returns the key currently pressed, if it maps to a known key code.
   *
   * @return KeyCode|null The pressed key, or null when nothing recognisable is down.
   */
  public static function getPressedKeyCode(): ?KeyCode
  {
    return self::$keyPress;
  }

  public static function getInputSource(): InputSourceInterface
  {
    return self::$inputSource ??= new TerminalInputSource();
  }

  public static function setInputSource(InputSourceInterface $source): void
  {
    self::$previousKeyPress = self::$keyPress = null;
    self::$inputSource = $source;
  }

  /**
   * Enables non-blocking mode.
   *
   * @return void
   * @throws RuntimeException Thrown if non-blocking mode could not be enabled.
   */
  public static function enableNonBlockingMode(): void
  {
    if (false === stream_set_blocking(STDIN, false)) {
      throw new RuntimeException('Failed to enable non-blocking mode.');
    }
  }

  /**
   * Disables non-blocking mode.
   *
   * @return void
   * @throws RuntimeException Thrown if non-blocking mode could not be disabled.
   */
  public static function disableNonBlockingMode(): void
  {
    if (false === stream_set_blocking(STDIN, true)) {
      throw new RuntimeException('Failed to disable non-blocking mode.');
    }
  }

  /**
   * Handles input from the user.
   *
   * @return void
   */
  public static function handleInput(): void
  {
    self::$previousKeyPress = self::$keyPress;
    self::$keyPress = self::getInputSource()->poll();

    if (self::$keyPress !== null) {
      self::$eventManager?->dispatchEvent(event: new KeyboardEvent(key: self::$keyPress->value));
    }
  }

  /**
   * Clears the cached key state and optionally drains any buffered input.
   *
   * This is useful after scene or modal transitions so a held confirm/cancel
   * input does not immediately trigger the next screen.
   *
   * @param bool $drainBufferedInput Whether to consume any currently buffered input bytes.
   * @return void
   */
  public static function resetState(bool $drainBufferedInput = false): void
  {
    self::$previousKeyPress = self::$keyPress = null;
    self::getInputSource()->reset($drainBufferedInput);
  }

  /**
   * Returns the value of the virtual axis identified by axisName.
   *
   * @param AxisName $axisName The name of the axis.
   * @return float Returns the value of the virtual axis identified by axisName.
   */
  public static function getAxis(AxisName $axisName): float
  {
    [$negativeAction, $positiveAction] = match ($axisName) {
      AxisName::HORIZONTAL => ['left', 'right'],
      AxisName::VERTICAL => ['up', 'down'],
    };

    if (self::isButtonDown($negativeAction)) {
      return -1;
    }

    if (self::isButtonDown($positiveAction)) {
      return 1;
    }

    return 0;
  }

  /**
   * Checks if a key is pressed.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is pressed, false otherwise.
   */
  public static function isKeyPressed(KeyCode $keyCode): bool
  {
    return self::$keyPress === $keyCode;
  }

  /**
   * Checks if all keys are pressed.
   *
   * @param array $keyCodes The key codes to check.
   * @return bool Returns true if all keys are pressed, false otherwise.
   */
  public static function areAllKeysPressed(array $keyCodes): bool
  {
    foreach ($keyCodes as $keyCode) {
      if (!self::isKeyPressed($keyCode)) {
        return false;
      }
    }
    return true;
  }

  /**
   * Checks if any key is pressed.
   *
   * @param KeyCode[] $keyCodes The key codes to check.
   * @return bool Returns true if any key is pressed, false otherwise.
   */
  public static function isAnyKeyPressed(array $keyCodes): bool
  {
    return array_any($keyCodes, fn($keyCode) => self::isKeyDown($keyCode));
  }

  /**
   * Checks if any of the given key codes was released.
   *
   * @param KeyCode[] $keyCodes The key codes to check.
   * @return bool Returns true if any key is released, false otherwise.
   */
  public static function isAnyKeyReleased(array $keyCodes): bool
  {
    foreach ($keyCodes as $keyCode) {
      if (self::isKeyUp($keyCode)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Checks if a key is pressed down.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is pressed down, false otherwise.
   */
  public static function isKeyDown(KeyCode $keyCode): bool
  {
    return self::$keyPress === $keyCode && self::$previousKeyPress !== self::$keyPress;
  }

  /**
   * Checks if a key is released.
   *
   * @param KeyCode $keyCode The key code to check.
   * @return bool Returns true if the key is released, false otherwise.
   */
  public static function isKeyUp(KeyCode $keyCode): bool
  {
    return self::$keyPress === null && self::$previousKeyPress === $keyCode;
  }

  /**
   * Checks if a button is pressed.
   *
   * @param string $name The name of the button to check.
   * @return bool Returns true if the button is pressed, false otherwise.
   */
  public static function isButtonDown(string $name): bool
  {
    $button = self::$config[$name] ?? [];
    return self::isAnyKeyPressed($button['keys'] ?? []);
  }

  public static function disableEcho(): void
  {
    system('stty cbreak -echo');
  }

  public static function enableEcho(): void
  {
    system('stty -cbreak echo');

    // Turn on cursor blinking
    Console::cursor()->enableBlinking();
  }
}
