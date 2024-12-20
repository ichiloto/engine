<?php

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

if (! function_exists('clamp') ) {
  /**
   * Returns the given value clamped between the given min and max values.
   *
   * @param int|float $value The value to clamp.
   * @param int|float $min The minimum value.
   * @param int|float $max The maximum value.
   * @return int|float The clamped value.
   */
  function clamp(int|float $value, int|float $min, int|float $max): int|float
  {
    return max($min, min($max, $value));
  }
}

if (! function_exists('lerp') ) {
  /**
   * Linearly interpolates between the given start and end values.
   *
   * @param float $start The start value.
   * @param float $end The end value.
   * @param float $amount The amount to interpolate.
   * @return float The interpolated value.
   */
  function lerp(float $start, float $end, float $amount): float
  {
    $amount = clamp($amount, 0, 1);

    return $start + ($end - $start) * $amount;
  }
}

if (! function_exists('wrap') ) {
  /**
   * Wraps the given value between the given min and max values.
   *
   * @param int $value The value to wrap.
   * @param int $min The minimum value.
   * @param int $max The maximum value.
   * @return int The wrapped value.
   */
  function wrap(int $value, int $min, int $max): int
  {
    $range = $max - $min + 1;

    if ($range == 0) {
      return $min;
    }

    if ($value < $min) {
      $value += $range * ceil(($min - $value) / $range);
    }

    return $min + (($value - $min) % $range + $range) % $range;
  }
}

if (! function_exists('wrap_text') ) {
  /**
   * Returns the given text wrapped to the given width.
   *
   * @param string $text The text to wrap.
   * @param int $width The width to wrap to.
   * @param bool $breakWords Whether to break words or not.
   * @return string The wrapped text.
   */
  function wrap_text(string $text, int $width, bool $breakWords = true): string
  {
    $lines = explode("\n", $text);
    $wrappedLines = [];

    foreach ($lines as $line) {
      $wrappedLines = array_merge($wrappedLines, explode("\n", wordwrap($line, $width, "\n", $breakWords)));
    }

    return implode("\n", $wrappedLines);
  }
}

/* Dialog functions */
if (! function_exists('alert') ){
  /**
   * Shows an alert dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "Alert".
   * @param int $width The width of the dialog. Defaults to 34.
   * @return void
   * @throws Exception
   */
  function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    Console::alert($message, $title, $width);
  }
}

if (! function_exists('confirm') ) {
  /**
   * Shows a confirm dialog with the given message and title. Returns true if the user confirmed, false otherwise.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "Confirm".
   * @param int $width The width of the dialog. Defaults to 34.
   * @return bool Whether the user confirmed or not.
   * @throws Exception If the game instance is not set.
   */
  function confirm(string $message, string $title = 'Confirm', int $width = DEFAULT_DIALOG_WIDTH): bool
  {
    return Console::confirm($message, $title, $width);
  }
}

if (! function_exists('prompt') ) {
  /**
   * Shows a prompt dialog with the given message and title. Returns the user's input.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "Prompt".
   * @param string $default The default value of the input. Defaults to an empty string.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return string The user's input.
   * @throws Exception
   */
  function prompt(
    string $message,
    string $title = 'Prompt',
    string $default = '',
    int    $width = DEFAULT_DIALOG_WIDTH
  ): string
  {
    return Console::prompt($message, $title, $default, $width);
  }
}

if (! function_exists('select') ) {
  /**
   * Shows a select dialog with the given message and title. Returns the index of the selected option.
   *
   * @param string $message The message to show.
   * @param array $options The options to show.
   * @param string $title The title of the dialog. Defaults to "Select".
   * @param int $default The default option. Defaults to 0.
   * @param Vector2|null $position The position of the dialog. Defaults to null.
   * @param int $width The width of the dialog. Defaults to 34.
   * @return int The index of the selected option.
   * @throws Exception
   */
  function select(
    string   $message,
    array    $options,
    string   $title = '',
    int      $default = 0,
    ?Vector2 $position = null,
    int      $width = DEFAULT_SELECT_DIALOG_WIDTH
  ): int
  {
    return Console::select($message, $options, $title, $default, $position, $width);
  }
}

if (! function_exists('show_text') ) {
  /**
   * Shows a text dialog with the given message and title.
   *
   * @param string $message The message to show.
   * @param string $title The title of the dialog. Defaults to "".
   * @param string $help The help text to show. Defaults to "".
   * @param WindowPosition $position The position of the dialog. Defaults to BOTTOM (i.e. the bottom of the screen).
   * @param float $charactersPerSecond The number of characters to display per second.
   * @return void
   * @throws Exception
   */
  function show_text(
    string         $message,
    string         $title = '',
    string         $help = '',
    WindowPosition $position = WindowPosition::BOTTOM,
    float          $charactersPerSecond = 1
  ): void
  {
    Console::showText($message, $title, $help, $position, $charactersPerSecond);
  }
}

if (! function_exists('notify') ) {
  /**
   * Notifies the user with the given title and text.
   *
   * @param Game $game The game to notify.
   * @param NotificationChannel $channel The notification channel.
   * @param string $title The notification title.
   * @param string $text The notification text.
   * @param NotificationDuration|float $duration The notification duration.
   * @return void
   */
  function notify(
    Game                       $game,
    NotificationChannel        $channel,
    string                     $title,
    string                     $text,
    NotificationDuration|float $duration = NotificationDuration::LONG
  ): void
  {
    $notification = new Notification($game, $channel, $title, $text, $duration);
    NotificationManager::getInstance($game)->notify($notification);
  }
}

/* Events */

if (! function_exists('broadcast') ) {
  /**
   * Broadcasts the given event.
   *
   * @param Game $game The game to broadcast the event to.
   * @param EventInterface $event The event to broadcast.
   * @return void
   */
  function broadcast(Game $game, EventInterface $event): void
  {
    $eventManager = EventManager::getInstance($game);
    $eventManager->dispatchEvent($event);
  }
}

/* Text */
if (! function_exists('strip_ansi') ) {
  /**
   * Returns the given text with all ANSI escape sequences removed.
   *
   * @param string $input The text to remove the escape sequences from.
   * @return string The text with all escape sequences removed.
   */
  function strip_ansi(string $input): string
  {
    $pattern = "/\e\[[0-9;]*m/";
    return preg_replace($pattern, '', $input);
  }
}

if (! function_exists('bytes_to_human_readable') ) {
  /**
   * Converts the given bytes into a human-readable format.
   *
   * @param int $bytes The bytes to convert.
   * @param int $decimals The number of decimals to show. Defaults to 2.
   * @return string The human-readable bytes.
   */
  function bytes_to_human_readable(int $bytes, int $decimals = 2): string
  {
    $size = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . @$size[$factor];
  }
}

if (! function_exists('bytes_format') ) {
  /**
   * Formats the given bytes into a human-readable format.
   *
   * @param int $bytes The bytes to format.
   * @param int $decimals The number of decimals to show. Defaults to 2.
   * @return string The formatted bytes.
   */
  function bytes_format(int $bytes, int $decimals = 2): string
  {
    return bytes_to_human_readable($bytes, $decimals);
  }
}

/* Configs */
if (! function_exists('config') ) {
  /**
   * Gets the value of the given configuration path.
   *
   * @param class-string $configClassname The classname of the configuration.
   * @param string $path The path of the configuration.
   * @param mixed $default The default value.
   *
   * @return mixed The value of the configuration.
   */
  function config(string $configClassname, string $path, mixed $default = null): mixed
  {
    return ConfigStore::get($configClassname)->get($path, $default);
  }
}

/* Resources */
if (! function_exists('asset') ) {
  /**
   * Returns the content of the asset file with the given path.
   *
   * @param string $path The path of the asset file.
   * @param bool $asArray Whether to return the content as an array or not.
   * @return string|array The content of the asset file.
   */
  function asset(string $path, bool $asArray = false): string|array
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', $path);

    if (! file_exists($filename) ) {
      throw new RuntimeException("Asset file not found: $filename");
    }

    if ($asArray) {
      return require $filename;
    }

    $content = file_get_contents($filename);

    if (false === $content) {
      throw new RuntimeException("Failed to read asset file: $filename");
    }

    return $content;
  }
}

if (! function_exists('graphics') ) {
  /**
   * Opens a graphics resource file
   *
   * @param string $path The path of the graphics resource file.
   * @return string
   */
  function graphics(string $path): string
  {
    return asset("Graphics/$path.txt");
  }
}

/* System */
if (! function_exists('os_program_exists') ) {
  /**
   * Checks if the given program exists in the system.
   *
   * @param string $programName The name of the program to check.
   * @return bool Whether the program exists or not.
   */
  function os_program_exists(string $programName): bool
  {
    $locatorProgram = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    $output = shell_exec("$locatorProgram $programName");
    return ! empty($output);
  }
}

if (! file_exists('get_local_timezone') ) {
  /**
   * Gets the local timezone.
   *
   * @return string The local timezone.
   */
  function get_local_timezone(): string
  {
    $timezoneCommand = 'timedatectl';

    # If timedatectl is not available raise an exception.
    if (! os_program_exists($timezoneCommand) ) {
      throw new RuntimeException("The command $timezoneCommand is not available.");
    }

    # Get output of timedatectl
    $output = shell_exec($timezoneCommand);

    # Split the output by newline
    $lines = explode("\n", $output);

    # Loop through the lines and extract the timezone
    foreach ($lines as $line) {
      if (str_contains($line, 'Local time')) {
        // Extract the timezone part
        preg_match('/Local time: (.*)\s([A-Z]{1,5})$/', $line, $matches);
        return $matches[2] ?? '';
      }
    }

    return '';
  }
}

/* Game */
if (! function_exists('quit_game') ) {
  /**
   * Quits the game.
   *
   * @param Game $game
   * @return void
   * @throws Exception Thrown if the user cancels the quit operation.
   */
  function quit_game(Game $game): void
  {
    if (confirm('Are you sure you want to quit the game?')) {
      $game->quit();
    }
  }
}

if (! function_exists('get_message') ) {
  /**
   * Gets the message with the given path.
   *
   * @param string $path The path of the message.
   * @param string $default The default message.
   * @return string The message.
   */
  function get_message(string $path, string $default): string
  {
    return config(ProjectConfig::class, "messages.{$path}", $default);
  }
}

if (! function_exists('get_screen_width') ) {
  /**
   * Returns the screen width.
   *
   * @return int The screen width.
   */
  function get_screen_width(): int
  {
    return config(PlaySettings::class, 'width', DEFAULT_SCREEN_WIDTH);
  }
}

if (! function_exists('get_screen_height') ) {
  /**
   * Returns the screen height.
   *
   * @return int The screen height.
   */
  function get_screen_height(): int
  {
    return config(PlaySettings::class, 'height', DEFAULT_SCREEN_HEIGHT);
  }
}

/* Misc. */
if (! function_exists('debug_get_backtrace') ) {
  function debug_get_backtrace(): string
  {
    $backtrace = debug_backtrace();
    $output = '';

    foreach ($backtrace as $i => $trace) {
      $output .= "#$i: ";

      if (isset($trace['file'])) {
        $output .= $trace['file'] . ':' . $trace['line'];
      }

      if (isset($trace['class'])) {
        $output .= ' ' . $trace['class'] . $trace['type'] . $trace['function'];
      } else {
        $output .= ' ' . $trace['function'];
      }

      $output .= "\n";
    }

    return $output;
  }
}

if (! function_exists('compare_items') ) {
  /**
   * Compares two inventory items.
   *
   * @param InventoryItem $a The first item.
   * @param InventoryItem $b The second item.
   * @return int The comparison result. Returns -1 if $a is less than $b, 0 if they are equal, and 1 if $a is greater than $b.
   */
  function compare_items(InventoryItem $a, InventoryItem $b): int
  {
    return $a->name <=> $b->name;
  }
}