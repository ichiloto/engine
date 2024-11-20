<?php

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

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
  function notify(
    NotificationChannel        $channel,
    string                     $title,
    string                     $text,
    NotificationDuration|float $duration = NotificationDuration::SHORT
  ): void
  {
    $notification = new Notification($channel, $title, $text, $duration);
    NotificationManager::getInstance()->notify($notification);
  }
}

/* Events */

if (! function_exists('broadcast') ) {
  /**
   * Broadcasts the given event.
   *
   * @param EventInterface $event The event to broadcast.
   * @return void
   */
  function broadcast(EventInterface $event): void
  {
    $eventManager = EventManager::getInstance();
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
   * @return string The content of the asset file.
   */
  function asset(string $path): mixed
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', $path);

    if (! file_exists($filename) ) {
      throw new RuntimeException("Asset file not found: $filename");
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