<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;

/** Synchronous host scope for negotiated presentation capabilities, independent of any live Game. */
final class PresentationSession
{
  /** @var Closure(string): bool|null */
  private static ?Closure $supports = null;

  /** @param Closure(string): bool $supports */
  public static function runForSession(Closure $supports, Closure $operation): mixed
  {
    $previous = self::$supports;
    self::$supports = $supports;
    try { return $operation(); }
    finally { self::$supports = $previous; }
  }

  public static function supportsCapability(string $capability): bool
  {
    return self::$supports !== null && (self::$supports)($capability);
  }
}
