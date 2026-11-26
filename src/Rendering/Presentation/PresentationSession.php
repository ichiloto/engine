<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** Synchronous host scope for negotiated presentation capabilities, independent of any live Game. */
final class PresentationSession
{
  /** @var Closure(string): bool|null */
  private static ?Closure $supports = null;
  private static bool $consumerAdmitsCanvasImages = false;

  /** @param Closure(string): bool $supports */
  public static function runForSession(Closure $supports, Closure $operation,
    bool $consumerAdmitsCanvasImages = false): mixed
  {
    $previous = self::$supports;
    $previousAdmission = self::$consumerAdmitsCanvasImages;
    self::$supports = $supports;
    self::$consumerAdmitsCanvasImages = $consumerAdmitsCanvasImages;
    try { return $operation(); }
    finally {
      self::$supports = $previous;
      self::$consumerAdmitsCanvasImages = $previousAdmission;
    }
  }

  public static function supportsCapability(string $capability): bool
  {
    return self::$supports !== null && (self::$supports)($capability);
  }

  /** The frame's consumer enforces its own aggregate image byte limits before display. */
  public static function usesConsumerCanvasImageAdmission(): bool
  {
    return self::$consumerAdmitsCanvasImages || self::supportsCapability(RendererSessionConfig::ASSET_HOLDS);
  }
}
