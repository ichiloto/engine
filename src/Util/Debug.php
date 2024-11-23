<?php

namespace Ichiloto\Engine\Util;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Config\AppConfig;
use RuntimeException;
use Stringable;

/**
 * The debug utility.
 *
 * @package Ichiloto\Engine\Util
 */
final class Debug
{
  // Log levels.
  const int DEBUG = 0;
  const int INFO = 1;
  const int WARNING = 2;
  const int ERROR = 3;
  /**
   * @var int The log level.
   */
  protected static int $logLevel = 1;
  /**
   * @var string The log directory path.
   */
  protected static string $logDirectory = '';

  /**
   * Configures the debug utility.
   *
   * @param array $options The options to use.
   * @return void
   */
  public static function configure(array $options = []): void
  {
    self::$logLevel = $options['log_level'] ?? self::INFO;
    self::$logDirectory = $options['log_directory'] ?? Path::join(Path::getCurrentWorkingDirectory(), 'logs');
  }

  /**
   * Gets the log directory.
   *
   * @return string The log directory.
   */
  private static function getLogDirectory(): string
  {
    return self::$logDirectory;
  }

  /**
   * Gets the log file path.
   *
   * @param string $filename The filename.
   * @return string The log file path.
   */
  private static function getLogFilePath(string $filename): string
  {
    $path = Path::join(self::getLogDirectory(), $filename);

    if (! file_exists($path) ) {
      $file = fopen($path, 'w');

      if (false === $file) {
        throw new RuntimeException("Failed to write to the $filename log.");
      }

      fclose($file);
    }

    return $path;
  }

  /**
   * Logs a debug message.
   *
   * @param Stringable|string $message The message to log.
   * @return void
   */
  public static function log(Stringable|string $message): void
  {
    if (false === error_log(self::getFormattedMessage($message), 3, self::getLogFilePath('debug.log'))) {
      throw new RuntimeException("Failed to write to the debug log.");
    }
  }

  /**
   * Logs an info message.
   *
   * @param Stringable|string $message The message to log.
   * @return void
   */
  public static function info(Stringable|string $message): void
  {
    if (self::$logLevel < self::INFO) {
      return;
    }

    if (false === error_log(self::getFormattedMessage($message, 'INFO'), 3, self::getLogFilePath('debug.log'))) {
      throw new RuntimeException("Failed to write to the debug log.");
    }
  }

  /**
   * Logs a warning message.
   *
   * @param Stringable|string $message The message to log.
   * @return void
   */
  public static function warn(Stringable|string $message): void
  {
    if (self::$logLevel < self::WARNING) {
      return;
    }

    if (false === error_log(self::getFormattedMessage($message, 'WARNING'), 3, self::getLogFilePath('debug.log'))) {
      throw new RuntimeException("Failed to write to the debug log.");
    }
  }

  /**
   * Logs an error message to the error log.
   *
   * @param Stringable|string $message The message to log.
   * @throws RuntimeException Thrown if the error log file cannot be written to.
   */
  public static function error(Stringable|string $message): void
  {
    if (false === error_log(self::getFormattedMessage($message, 'ERROR'), 3, self::getLogFilePath('error.log'))) {
      throw new RuntimeException("Failed to write to the debug log.");
    }

    if (self::$logLevel < self::ERROR) {
      return;
    }

    if (false === error_log(self::getFormattedMessage($message, 'ERROR'), 3, self::getLogFilePath('debug.log'))) {
      throw new RuntimeException("Failed to write to the debug log.");
    }
  }

  /**
   * Gets the formatted message.
   *
   * @param Stringable|string $message The message.
   * @param string $prefix The prefix.
   * @return string The formatted message.
   */
  private static function getFormattedMessage(Stringable|string $message, string $prefix = 'DEBUG'): string
  {
    return sprintf("[%s] [%s] - %s", date(DATE_ATOM), $prefix, $message) . PHP_EOL;
  }
}