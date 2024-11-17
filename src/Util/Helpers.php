<?php

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

  if ($range == 0)
  {
    return $min;
  }

  if ($value < $min)
  {
    $value += $range * ceil(($min - $value) / $range);
  }

  return $min + (($value - $min) % $range + $range) % $range;
}

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

  foreach ($lines as $line)
  {
    $wrappedLines = array_merge($wrappedLines, explode("\n", wordwrap($line, $width, "\n", $breakWords)));
  }

  return implode("\n", $wrappedLines);
}

/* Dialog functions */
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
  int $width = DEFAULT_DIALOG_WIDTH
): string
{
  return Console::prompt($message, $title, $default, $width);
}

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
  string $message,
  array $options,
  string $title = '',
  int $default = 0,
  ?Vector2 $position = null,
  int $width = DEFAULT_SELECT_DIALOG_WIDTH
): int
{
  return Console::select($message, $options, $title, $default, $position, $width);
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
function show_text(
  string $message,
  string $title = '',
  string $help = '',
  WindowPosition $position = WindowPosition::BOTTOM,
  float $charactersPerSecond = 1
): void
{
  Console::showText($message, $title, $help, $position, $charactersPerSecond);
}

function notify(
  NotificationChannel $channel,
  string $title,
  string $text,
  NotificationDuration|float $duration = NotificationDuration::SHORT
): void
{
  $notification = new Notification($channel, $title, $text, $duration);
  NotificationManager::getInstance()->notify($notification);
}

/* Events */

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

/* Text */
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

/* Configs */
if (! function_exists('config') ) {
  /**
   * Gets the value of the given configuration path.
   *
   * @param string $path The path of the configuration.
   * @param mixed $default The default value.
   *
   * @return mixed The value of the configuration.
   */
  function config(string $path, mixed $default = null): mixed
  {
    return Config::get($path, $default);
  }
}