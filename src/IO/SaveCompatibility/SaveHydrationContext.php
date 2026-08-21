<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

/**
 * Defers content-sensitive Character hydration while a save is decoded.
 *
 * PHP invokes nested __unserialize() methods before the outer save envelope is
 * available. Characters therefore retain their raw save arrays until aliases
 * and tombstones have been applied by the compatibility pipeline.
 */
final class SaveHydrationContext
{
  private static int $depth = 0;

  public static function begin(): void
  {
    self::$depth++;
  }

  public static function end(): void
  {
    self::$depth = max(0, self::$depth - 1);
  }

  public static function shouldDeferCharacters(): bool
  {
    return self::$depth > 0;
  }

  /** Content-backed inventory definitions must also wait for migration. */
  public static function shouldDeferInventoryItems(): bool
  {
    return self::$depth > 0;
  }
}
