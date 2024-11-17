<?php

namespace Ichiloto\Engine\IO\Console;

use Symfony\Component\Console\Output\ConsoleOutput;

class Console
{
  /**
   * @var array<string> $buffer The buffer.
   */
  private static array $buffer = [];
  /**
   * @var string $previousTerminalSettings The previous terminal settings.
   */
  private static string $previousTerminalSettings = '';

  private function __construct()
  {
  }

  /**
   * Initializes the console.
   *
   * @return void
   */
  public static function init(): void
  {
    self::clear();
    Console::cursor()->disableBlinking();
  }

  /**
   * Resets the console.
   *
   * @return void
   */
  public static function reset(): void
  {
    system('tput reset');
    echo "\033c";
    self::cursor()->enableBlinking();
  }

  /**
   * Enables the line wrap.
   *
   * @return void
   */
  public static function enableLineWrap(): void
  {
    echo "\033[7h";
  }

  /**
   * Disables the line wrap.
   *
   * @return void
   */
  public static function disableLineWrap(): void
  {
    echo "\033[7l";
  }

  /**
   * Returns the cursor.
   *
   * @return Cursor The cursor.
   */
  public static function cursor(): Cursor
  {
    return Cursor::getInstance();
  }

  /* Scrolling */
  /**
   * Enables scrolling.
   *
   * @param int|null $start The line to start scrolling.
   * @param int|null $end The line to end scrolling.
   * @return void
   */
  public static function enableScrolling(?int $start = null, ?int $end = null): void
  {
    echo match(true) {
      $start !== null && $end !== null => "\033[{$start};{$end}r",
      $start !== null => "\033[{$start}r",
      $end !== null => "\033[;{$end}r",
      default => "\033[r",
    };
  }

  /**
   * Disables scrolling.
   *
   * @return void
   */
  public static function disableScrolling(): void
  {
    echo "\033[?7l";
  }

  /**
   * Clears the console.
   *
   * @return void
   */
  public static function clear(): void
  {
    self::$buffer = self::getEmptyBuffer();
    if (PHP_OS_FAMILY === 'Windows') {
      system('cls');
    } else {
      system('clear');
    }
  }

  /**
   * Sets the terminal name.
   *
   * @param string $name The name of the terminal.
   * @return void
   */
  public static function setTerminalName(string $name): void
  {
    echo "\033]0;$name\007";
  }

  /**
   * Sets the terminal size.
   *
   * @param int $width The width of the terminal.
   * @param int $height The height of the terminal.
   * @return void
   */
  public static function setTerminalSize(int $width, int $height): void
  {
    echo "\033[8;$height;{$width}t";
  }

  /**
   * Saves the terminal settings.
   *
   * @return void
   */
  public static function saveTerminalSettings(): void
  {
    self::$previousTerminalSettings = shell_exec('stty -g') ?? '';
  }

  /**
   * Restores the terminal settings.
   *
   * @return void
   */
  public static function restoreTerminalSettings(): void
  {
    shell_exec('stty ' . self::$previousTerminalSettings);
  }

  /**
   * Writes text to the console at the specified position.
   *
   * @param iterable|string $message The text to write.
   * @param int $x The x position.
   * @param int $y The y position.
   * @return void
   */
  public static function write(iterable|string $message, int $x, int $y): void
  {
    $cursor = self::cursor();
    $output = new ConsoleOutput();
    $cursor->moveTo(0, $y);
    $output->write($message);
  }

  /**
   * Erases
   *
   * @param int $x
   * @param int $y
   * @return void
   */
  public static function erase(int $x, int $y): void
  {
    self::write(' ', $x, $y);
  }

  /**
   * Gets the buffer.
   *
   * @return string[] The buffer.
   */
  public static function getBuffer(): array
  {
    return self::$buffer;
  }

  /**
   * Returns the character at the specified position.
   *
   * @param int $x The x position.
   * @param int $y The y position.
   * @return string The character at the specified position.
   */
  public static function charAt(int $x, int $y): string
  {
    if ($x < 0 || $x > DEFAULT_SCREEN_WIDTH || $y < 1 || $y > DEFAULT_SCREEN_HEIGHT) {
      return '';
    }

    $char = substr(self::$buffer[$y], $x, 1);
    return ord($char) === 0 ? ' ' : $char;
  }

  /**
   * Returns an empty buffer.
   *
   * @return array<string> The empty buffer.
   */
  private static function getEmptyBuffer(): array
  {
    return array_fill(0, DEFAULT_SCREEN_HEIGHT, str_repeat(' ', DEFAULT_SCREEN_WIDTH));
  }
}