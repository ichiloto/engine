<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Closure;
use GdImage;

/** Decodes preflighted PNGs for pixel inspection. Consumers own failed-read diagnostics. */
final class PngAssetDecoder
{
  private function __construct()
  {
  }

  public static function decodeFile(string $path): GdImage|false
  {
    return self::decode(static fn(): GdImage|false => imagecreatefrompng($path));
  }

  public static function decodeBytes(string $bytes): GdImage|false
  {
    return self::decode(static fn(): GdImage|false => imagecreatefromstring($bytes));
  }

  /** @param Closure(): (GdImage|false) $decode */
  private static function decode(Closure $decode): GdImage|false
  {
    // GD can emit several warnings for one rejected image. Its result feeds the owning diagnostic.
    set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE);
    try {
      return $decode();
    } finally {
      restore_error_handler();
    }
  }
}
