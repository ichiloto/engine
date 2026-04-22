<?php

namespace Ichiloto\Engine\IO\Console;

use Exception;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Terminal;

/**
 * Represents the console.
 *
 * @package Ichiloto\Engine\IO\Console
 */
class Console
{
  /**
   * Placeholder marker used for continuation cells of wide terminal symbols.
   */
  private const string WIDE_SYMBOL_CONTINUATION = "\0";

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
    $availableSize = self::getAvailableSize();

    self::$game = $game;
    Console::cursor()->disableBlinking();
    self::$width = intval($options['width'] ?? $availableSize['width']);
    self::$height = intval($options['height'] ?? $availableSize['height']);
    self::$output = new ConsoleOutput();
    self::clear();
  }

  /**
   * Returns the currently available terminal size.
   *
   * @return array{width: int, height: int} The terminal width and height.
   */
  public static function getAvailableSize(): array
  {
    if ($size = self::readAvailableSizeFromStty()) {
      return $size;
    }

    if ($size = self::readAvailableSizeFromTput()) {
      return $size;
    }

    if ($size = self::readAvailableSizeFromSymfonyTerminal()) {
      return $size;
    }

    $width = getenv('COLUMNS');
    $height = getenv('LINES');
    $size = self::normalizeAvailableSize($width, $height);

    if ($size) {
      return $size;
    }

    return [
      'width' => max(1, self::$width ?: DEFAULT_SCREEN_WIDTH),
      'height' => max(1, self::$height ?: DEFAULT_SCREEN_HEIGHT),
    ];
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
    self::$width = $width;
    self::$height = $height;
    echo "\033[8;$height;{$width}t";
  }

  /**
   * Synchronizes the console's internal dimensions with the terminal.
   *
   * This updates the backing buffer without forcing the terminal emulator to
   * resize, which is useful when the user manually changes the window size.
   *
   * @param int $width The current terminal width.
   * @param int $height The current terminal height.
   * @return void
   */
  public static function syncDimensions(int $width, int $height): void
  {
    self::$width = max(1, $width);
    self::$height = max(1, $height);
    self::$buffer = self::getEmptyBuffer();
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
   * @param int|float $x The x position.
   * @param int|float $y The y position.
   * @return void
   */
  public static function write(iterable|string $message, int|float $x, int|float $y): void
  {
    $textRows = is_string($message) ? explode("\n", $message) : $message;
    $x = (int)floor($x);
    $y = (int)floor($y);
    $x = max(0, min($x, max(0, self::$width - 1)));

    foreach ($textRows as $rowIndex => $text) {
      $currentBufferRow = $y + $rowIndex;

      if ($currentBufferRow < 0 || $currentBufferRow >= self::$height) {
        continue;
      }

      if (!isset(self::$buffer[$currentBufferRow])) {
        self::$buffer[$currentBufferRow] = str_repeat(' ', self::$width);
      }

      $rowCells = self::rowToCells(self::$buffer[$currentBufferRow]);
      $text = TerminalText::truncateToWidth((string)$text, max(0, self::$width - $x));
      $cellCursor = $x;

      foreach (TerminalText::visibleSymbols($text) as $symbol) {
        $symbolWidth = max(1, TerminalText::displayWidth($symbol));

        if ($cellCursor >= self::$width || $cellCursor + $symbolWidth > self::$width) {
          break;
        }

        self::clearCellRange($rowCells, $cellCursor, $symbolWidth);
        $rowCells[$cellCursor] = $symbol;

        for ($offset = 1; $offset < $symbolWidth && $cellCursor + $offset < self::$width; $offset++) {
          $rowCells[$cellCursor + $offset] = self::WIDE_SYMBOL_CONTINUATION;
        }

        $cellCursor += $symbolWidth;
      }

      self::$buffer[$currentBufferRow] = self::cellsToRow($rowCells);
      self::writeBufferRow($currentBufferRow);
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
    if ($x < 0 || $x >= self::$width || $y < 0 || $y >= self::$height) {
      return '';
    }

    $cells = self::rowToCells(self::$buffer[$y] ?? '');
    $anchorIndex = self::resolveCellAnchor($cells, $x);

    if ($anchorIndex === null) {
      return '';
    }

    $char = TerminalText::stripAnsi($cells[$anchorIndex] ?? ' ');

    return $char === '' ? ' ' : $char;
  }

  /**
   * Returns an empty buffer.
   *
   * @return array<string> The empty buffer.
   */
  private static function getEmptyBuffer(): array
  {
    return array_fill(0, self::$height, str_repeat(' ', self::$width));
  }

  /**
   * Attempts to read the current terminal size via stty.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromStty(): ?array
  {
    $commands = [
      'stty size < /dev/tty 2>/dev/null',
      'stty size 2>/dev/null',
    ];

    foreach ($commands as $command) {
      $size = self::parseSttySizeOutput(shell_exec($command) ?: '');

      if ($size) {
        return $size;
      }
    }

    return null;
  }

  /**
   * Attempts to read the current terminal size via tput.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromTput(): ?array
  {
    $width = shell_exec('tput cols 2>/dev/null');
    $height = shell_exec('tput lines 2>/dev/null');

    return self::normalizeAvailableSize($width, $height);
  }

  /**
   * Attempts to read the current terminal size from Symfony's terminal helper.
   *
   * Symfony caches width and height statically, so those cached values are
   * cleared before each probe to keep resize handling live.
   *
   * @return array{width: int, height: int}|null The detected size, if available.
   */
  protected static function readAvailableSizeFromSymfonyTerminal(): ?array
  {
    try {
      $reflection = new ReflectionClass(Terminal::class);

      foreach (['width', 'height'] as $propertyName) {
        if (! $reflection->hasProperty($propertyName)) {
          continue;
        }

        $property = $reflection->getProperty($propertyName);
        $property->setValue(null, null);
      }
    } catch (\Throwable) {
      return null;
    }

    $terminal = new Terminal();

    return self::normalizeAvailableSize($terminal->getWidth(), $terminal->getHeight());
  }

  /**
   * Parses the output of `stty size`.
   *
   * @param string $output The raw `stty size` output.
   * @return array{width: int, height: int}|null The parsed size, if valid.
   */
  protected static function parseSttySizeOutput(string $output): ?array
  {
    if (! preg_match('/^\s*(\d+)\s+(\d+)\s*$/', trim($output), $matches)) {
      return null;
    }

    return self::normalizeAvailableSize($matches[2], $matches[1]);
  }

  /**
   * Normalizes raw terminal size values into positive integer dimensions.
   *
   * @param mixed $width The raw width.
   * @param mixed $height The raw height.
   * @return array{width: int, height: int}|null The normalized size, if valid.
   */
  protected static function normalizeAvailableSize(mixed $width, mixed $height): ?array
  {
    if (! is_numeric($width) || ! is_numeric($height)) {
      return null;
    }

    $width = max(1, intval($width));
    $height = max(1, intval($height));

    return ['width' => $width, 'height' => $height];
  }

  /**
   * Flushes a single buffered row to the terminal without adding a trailing newline.
   *
   * Avoiding a final line-feed prevents full-screen renders from triggering
   * terminal scrolling when the last visible row is repainted.
   *
   * @param int $row The zero-based buffer row to flush.
   * @return void
   */
  private static function writeBufferRow(int $row): void
  {
    if (!isset(self::$buffer[$row])) {
      return;
    }

    self::cursor()->moveTo(1, $row + 1);

    if (self::$output) {
      self::$output->write(self::$buffer[$row]);
      return;
    }

    echo self::$buffer[$row];
  }

  /**
   * Expands a rendered row into terminal cells so wide glyphs occupy multiple slots.
   *
   * @param string $row The rendered buffer row.
   * @return string[] A cell buffer aligned to the console width.
   */
  private static function rowToCells(string $row): array
  {
    $cells = [];
    $cellIndex = 0;

    foreach (TerminalText::visibleSymbols($row) as $symbol) {
      $symbolWidth = max(1, TerminalText::displayWidth($symbol));

      if ($cellIndex >= self::$width) {
        break;
      }

      $cells[$cellIndex] = $symbol;

      for ($offset = 1; $offset < $symbolWidth && $cellIndex + $offset < self::$width; $offset++) {
        $cells[$cellIndex + $offset] = self::WIDE_SYMBOL_CONTINUATION;
      }

      $cellIndex += $symbolWidth;
    }

    return array_pad(array_slice($cells, 0, self::$width), self::$width, ' ');
  }

  /**
   * Converts a cell buffer back into a rendered row string.
   *
   * @param string[] $cells The cell buffer.
   * @return string The rendered row.
   */
  private static function cellsToRow(array $cells): string
  {
    $output = [];

    foreach (array_slice($cells, 0, self::$width) as $cell) {
      $output[] = $cell === self::WIDE_SYMBOL_CONTINUATION ? '' : $cell;
    }

    return implode('', $output);
  }

  /**
   * Clears a cell range, removing any wide glyphs that overlap the target span.
   *
   * @param array<int, string> $cells The cell buffer.
   * @param int $start The starting cell.
   * @param int $width The number of cells to clear.
   * @return void
   */
  private static function clearCellRange(array &$cells, int $start, int $width): void
  {
    if ($width <= 0 || $start >= self::$width) {
      return;
    }

    $anchorsToClear = [];
    $end = min(self::$width - 1, max($start, $start + $width - 1));

    for ($cellIndex = max(0, $start); $cellIndex <= $end; $cellIndex++) {
      $anchorIndex = self::resolveCellAnchor($cells, $cellIndex);

      if ($anchorIndex === null) {
        continue;
      }

      $anchorsToClear[$anchorIndex] = true;
    }

    foreach (array_keys($anchorsToClear) as $anchorIndex) {
      $symbol = $cells[$anchorIndex] ?? ' ';
      $symbolWidth = $symbol === self::WIDE_SYMBOL_CONTINUATION
        ? 1
        : max(1, TerminalText::displayWidth($symbol));

      for ($offset = 0; $offset < $symbolWidth && $anchorIndex + $offset < self::$width; $offset++) {
        $cells[$anchorIndex + $offset] = ' ';
      }
    }
  }

  /**
   * Resolves the leading cell index for the symbol occupying the given cell.
   *
   * @param array<int, string> $cells The cell buffer.
   * @param int $cellIndex The cell index to inspect.
   * @return int|null The anchor cell index, or null if out of bounds.
   */
  private static function resolveCellAnchor(array $cells, int $cellIndex): ?int
  {
    if ($cellIndex < 0 || $cellIndex >= self::$width) {
      return null;
    }

    if (($cells[$cellIndex] ?? ' ') !== self::WIDE_SYMBOL_CONTINUATION) {
      return $cellIndex;
    }

    for ($anchorIndex = $cellIndex - 1; $anchorIndex >= 0; $anchorIndex--) {
      if (($cells[$anchorIndex] ?? ' ') !== self::WIDE_SYMBOL_CONTINUATION) {
        return $anchorIndex;
      }
    }

    return null;
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
