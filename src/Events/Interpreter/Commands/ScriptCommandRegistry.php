<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Assegai\Util\Path;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The process-wide script command catalogue selected during game bootstrap.
 *
 * A project declares its commands in `assets/Data/script-commands.php`, a
 * file returning a list of declarations. Without that file the project has
 * the Engine's commands only.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class ScriptCommandRegistry
{
  /** The declaration file, relative to the project's asset root. */
  public const string PROJECT_FILE = 'Data/script-commands.php';

  private static ?ScriptCommandCatalog $catalog = null;

  /**
   * Loads the project's declared commands, never retaining a prior project's.
   *
   * @param string|null $assetRoot The project's asset root; the working directory's `assets` when omitted.
   * @throws RuntimeException When the declarations cannot be used.
   */
  public static function configureFromProject(?string $assetRoot = null): void
  {
    self::reset();
    $assetRoot ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets');
    $filename = Path::join($assetRoot, self::PROJECT_FILE);

    if (! file_exists($filename) && ! is_link($filename)) {
      return;
    }

    try {
      if (! is_file($filename) || ! is_readable($filename)) {
        throw new InvalidArgumentException('The declaration must be a readable PHP file.');
      }

      $catalog = ScriptCommandCatalog::fromDeclarations((static fn(): mixed => require $filename)(), $filename);
      $catalog->assertHandlersLoadable();
      self::configure($catalog);
    } catch (Throwable $exception) {
      throw new RuntimeException(sprintf(
        'Script commands %s could not be loaded: %s',
        $filename,
        $exception->getMessage(),
      ), previous: $exception);
    }
  }

  public static function configure(?ScriptCommandCatalog $catalog): void
  {
    self::$catalog = $catalog;
  }

  /** The configured catalogue, or the Engine's commands when none is configured. */
  public static function getCatalog(): ScriptCommandCatalog
  {
    return self::$catalog ??= ScriptCommandCatalog::createEngineCatalog();
  }

  public static function reset(): void
  {
    self::$catalog = null;
  }
}
