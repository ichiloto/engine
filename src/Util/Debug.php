<?php

namespace Ichiloto\Engine\Util;

use Assegai\Util\Path;
use Ichiloto\Engine\Util\Config\AppConfig;
use RuntimeException;

final class Debug
{
  public const int DEBUG = 1;
  public const int INFO = 2;
  public const int WARNING = 3;
  public const int ERROR = 4;

  protected static int $logLevel = self::INFO;
  protected static ?string $logDirectory = null;

  public static function configure(array $options = []): void
  {
    self::$logLevel = max(self::DEBUG, intval($options["log_level"] ?? self::INFO));
    self::$logDirectory = $options["log_directory"] ?? Path::join(Path::getCurrentWorkingDirectory(), "logs");
  }

  public static function log(mixed $message): void
  {
    self::write(self::DEBUG, "DEBUG", $message, "debug.log");
  }

  public static function info(mixed $message): void
  {
    self::write(self::INFO, "INFO", $message, "debug.log");
  }

  public static function warn(mixed $message): void
  {
    self::write(self::WARNING, "WARN", $message, "debug.log");
  }

  public static function error(mixed $message): void
  {
    if (! self::isEnabled()) {
      return;
    }

    self::writeLine("ERROR", $message, "error.log");

    if (self::shouldLog(self::ERROR)) {
      self::writeLine("ERROR", $message, "debug.log");
    }
  }

  private static function write(int $level, string $prefix, mixed $message, string $filename): void
  {
    if (self::isEnabled() === false) {
      return;
    }

    if (self::shouldLog($level) === false) {
      return;
    }

    self::writeLine($prefix, $message, $filename);
  }

  private static function writeLine(string $prefix, mixed $message, string $filename): void
  {
    if (false === error_log(self::getFormattedMessage($message, $prefix), 3, self::getLogFilePath($filename))) {
      throw new RuntimeException("Failed to write to the $filename log.");
    }
  }

  private static function getFormattedMessage(mixed $message, string $prefix = "DEBUG"): string
  {
    return sprintf("[%s] [%s] %s", $prefix, date(DATE_ATOM), self::normalizeMessage($message)) . PHP_EOL;
  }

  private static function normalizeMessage(mixed $message): string
  {
    if (is_string($message)) {
      return $message;
    }

    if ($message instanceof \Stringable) {
      return (string) $message;
    }

    if (is_scalar($message)) {
      return strval($message);
    }

    if ($message === null) {
      return strval($message);
    }

    $encoded = json_encode($message);

    return $encoded === false ? get_debug_type($message) : $encoded;
  }

  private static function shouldLog(int $messageLevel): bool
  {
    return in_array(self::$logLevel, range(1, $messageLevel), true);
  }

  private static function getLogFilePath(string $filename): string
  {
    self::ensureLogDirectoryExists();
    $path = Path::join(self::getLogDirectory(), $filename);

    if (file_exists($path) === false) {
      $file = fopen($path, "w");

      if (false === $file) {
        throw new RuntimeException("Failed to write to the $filename log.");
      }

      fclose($file);
    }

    return $path;
  }

  private static function ensureLogDirectoryExists(): void
  {
    $logDirectory = self::getLogDirectory();

    if (is_dir($logDirectory)) {
      return;
    }

    if (false === mkdir($logDirectory, 0777, true)) {
      if (is_dir($logDirectory) === false) {
        throw new RuntimeException("Failed to create log directory.");
      }
    }
  }

  private static function getLogDirectory(): string
  {
    if (self::$logDirectory !== null) {
      return self::$logDirectory;
    }

    return Path::join(Path::getCurrentWorkingDirectory(), "logs");
  }

  private static function isEnabled(): bool
  {
    return config(AppConfig::class, "debug.enabled", false);
  }
}
