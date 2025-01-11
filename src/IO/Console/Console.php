<?php

namespace Ichiloto\Engine\IO\Console;

use Exception;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Represents the console.
 *
 * @package Ichiloto\Engine\IO\Console
 */
class Console
{
  /**
   * @var Game|null $game The game instance.
   */
  private static ?Game $game = null;
  /**
   * @var array<string> $buffer The buffer.
   */
  private static array $buffer = [];
  /**
   * @var string $previousTerminalSettings The previous terminal settings.
   */
  private static string $previousTerminalSettings = '';
  /**
   * @var int $width The width of the console.
   */
  private static int $width = DEFAULT_SCREEN_WIDTH;
  /**
   * @var int $height The height of the console.
   */
  private static int $height = DEFAULT_SCREEN_HEIGHT;
  /**
   * @var ConsoleOutput|null $output The console output.
   */
  private static ?ConsoleOutput $output = null;

  /**
   * Console constructor.
   */
  private function __construct()
  {
  }

  /**
   * Initializes the console.
   *
   * @param Game $game
   * @param array{width: int, height: int} $options
   * @return void
   */
  public static function init(Game $game, array $options = [
    'width' => DEFAULT_SCREEN_WIDTH,
    'height' => DEFAULT_SCREEN_HEIGHT,
  ]): void
  {
    self::$game = $game;
    self::clear();
    Console::cursor()->disableBlinking();
    self::$width = $options['width'] ?? get_screen_width();
    self::$height = $options['height'] ?? get_screen_height();
    self::$output = new ConsoleOutput();
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
      $start !== null && $end !== null => "\033[$start;{$end}r",
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
    $textRows = is_string($message) ? explode("\n", $message) : $message;
    $cursor = self::cursor();

    $output = '';
    foreach ($textRows as $rowIndex => $text) {
      $currentBufferRow = $y + $rowIndex;

      if (!isset(self::$buffer[$currentBufferRow])) {
        self::$buffer[$currentBufferRow] = str_repeat(' ', get_screen_width());
      }

      self::$buffer[$currentBufferRow] = substr_replace(self::$buffer[$currentBufferRow], $text, $x, mb_strlen($text));
      $output .= self::$buffer[$currentBufferRow] . "\n";
    }

    $cursor->moveTo(0, $y + 1);
    if (self::$output) {
      self::$output->write($output);
    } else {
      echo $output;
    }
  }

  /**
   * Erases output at the specified position.
   *
   * @param int $x The x position.
   * @param int $y The y position.
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
    if ($x < 0 || $x > get_screen_width() || $y < 1 || $y > get_screen_height()) {
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
    return array_fill(0, get_screen_height(), str_repeat(' ', get_screen_width()));
  }

  /**
   * Shows an alert dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog.
   * @param int $width The width of the dialog.
   * @return void
   * @throws Exception
   */
  public static function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    ModalManager::getInstance(self::$game)->alert($message, $title, $width);
  }

  /**
   * Shows a confirm dialog with the given message and title. Returns true if the user confirmed, false otherwise.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog.
   * @param int $width The width of the dialog.
   * @return bool Whether the user confirmed or not.
   * @throws Exception If the game instance is not set.
   */
  public static function confirm(string $message, string $title = 'Confirm', int $width = DEFAULT_DIALOG_WIDTH): bool
  {
    if (!self::$game) {
      throw new RuntimeException('The game instance is not set.');
    }

    return ModalManager::getInstance(self::$game)->confirm($message, $title, $width);
  }

  /**
   * Shows a prompt dialog with the given message and title. Returns the user's input.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "Prompt".
   * @param string $default The default value of the input. Defaults to an empty string.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return string The user's input.
   */
  public static function prompt(
    string $message,
    string $title = 'Prompt',
    string $default = '',
    int    $width = DEFAULT_DIALOG_WIDTH
  ): string
  {
    return ModalManager::getInstance(self::$game)->prompt($message, $title, $default, $width);
  }

  /**
   * Shows a select dialog with the given message and options. Returns the index of the selected option.
   *
   * @param string $message The message to show.
   * @param array $options The options to show.
   * @param string $title The title of the dialog. Defaults to "".
   * @param int $default The default option. Defaults to 0.
   * @param Vector2|null $position The position of the dialog. Defaults to null.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return int The index of the selected option.
   */
  public static function select(
    string   $message,
    array    $options,
    string   $title = '',
    int      $default = 0,
    ?Vector2 $position = null,
    int      $width = DEFAULT_SELECT_DIALOG_WIDTH
  ): int
  {
    return ModalManager::getInstance(self::$game)->select($message, $options, $title, $default, $position, $width);
  }

  /**
   * Shows a text dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "".
   * @param string $help The help text to show. Defaults to "".
   * @param WindowPosition $position The position of the dialog. Defaults to BOTTOM (i.e. the bottom of the screen).
   * @param float $charactersPerSecond The number of characters to display per second.
   * @return void
   */
  public static function showText(
    string         $message,
    string         $title = '',
    string         $help = '',
    WindowPosition $position = WindowPosition::BOTTOM,
    float          $charactersPerSecond = 1
  ): void
  {
    ModalManager::getInstance(self::$game)->showText($message, $title, $help, $position, $charactersPerSecond);
  }

  /**
   * Returns the width of the console.
   *
   * @return int The width of the console.
   */
  public static function getWidth(): int
  {
    return self::$width;
  }

  /**
   * Returns the height of the console.
   *
   * @return int The height of the console.
   */
  public static function getHeight(): int
  {
    return self::$height;
  }
}