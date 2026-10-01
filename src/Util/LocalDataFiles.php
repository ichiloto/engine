<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Util;

use RuntimeException;
use Throwable;

/** Filesystem operations for player-owned data, isolated from Game's fatal warning handler. */
final class LocalDataFiles
{
  private const int TEMPORARY_NAME_RANDOM_BYTES = 12;

  public static function runFileOperation(callable $operation): mixed
  {
    set_error_handler(static function (int $severity, string $message): never {
      throw new RuntimeException($message);
    });
    try {
      return $operation();
    } finally {
      restore_error_handler();
    }
  }

  public static function ensureDirectoryExists(string $directory): void
  {
    if (self::runFileOperation(static fn(): bool => is_dir($directory))) {
      return;
    }
    try {
      self::runFileOperation(static fn(): bool => mkdir($directory, 0777, true));
    } catch (RuntimeException $exception) {
      if (! self::runFileOperation(static fn(): bool => is_dir($directory))) {
        throw new RuntimeException("Could not create local data directory: $directory", previous: $exception);
      }
    }
    if (! self::runFileOperation(static fn(): bool => is_dir($directory))) {
      throw new RuntimeException("Could not create local data directory: $directory");
    }
  }

  /** Write beside the destination so an interrupted write cannot replace a valid file. */
  public static function writeAtomically(string $path, string $content): void
  {
    $directory = dirname($path);
    self::ensureDirectoryExists($directory);
    $temporary = $directory . '/.' . basename($path) . '-'
      . bin2hex(random_bytes(self::TEMPORARY_NAME_RANDOM_BYTES)) . '.tmp';
    $handle = self::runFileOperation(static fn() => fopen($temporary, 'xb'));
    if ($handle === false) {
      throw new RuntimeException("Could not create a temporary local data file in $directory.");
    }

    try {
      $offset = 0;
      $length = strlen($content);
      while ($offset < $length) {
        $written = self::runFileOperation(static fn() => fwrite($handle, substr($content, $offset)));
        if ($written === false || $written === 0) {
          throw new RuntimeException("Could not write local data file: $path");
        }
        $offset += $written;
      }
      if (! self::runFileOperation(static fn(): bool => fflush($handle))) {
        throw new RuntimeException("Could not flush local data file: $path");
      }
      if (! self::runFileOperation(static fn(): bool => fclose($handle))) {
        throw new RuntimeException("Could not close local data file: $path");
      }
      $handle = null;
      if (! self::runFileOperation(static fn(): bool => rename($temporary, $path))) {
        throw new RuntimeException("Could not replace local data file: $path");
      }
    } finally {
      if (is_resource($handle)) {
        try { self::runFileOperation(static fn(): bool => fclose($handle)); } catch (Throwable) {}
      }
      try {
        if (self::runFileOperation(static fn(): bool => is_file($temporary))) {
          self::runFileOperation(static fn(): bool => unlink($temporary));
        }
      } catch (Throwable $exception) {
        Debug::warn('Could not clean up temporary local data: ' . $exception->getMessage());
      }
    }
  }
}
