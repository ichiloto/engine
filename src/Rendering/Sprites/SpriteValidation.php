<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use InvalidArgumentException;

/** Shared structural limits for sprite intent and protocol-v1 presentation. */
final class SpriteValidation
{
  public static function validateDefinition(string $asset, int $width, int $height, int $layer): void
  {
    if ($asset === '' || preg_match('//u', $asset) !== 1 || str_contains($asset, "\0")
      || str_starts_with($asset, '/') || str_contains($asset, '\\')
      || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $asset) === 1
      || in_array('..', explode('/', $asset), true)) {
      throw new InvalidArgumentException('Sprite asset must be a relative UTF-8 path using forward slashes, without NUL or parent traversal.');
    }
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
      throw new InvalidArgumentException('Sprite dimensions must be between 1 and 4096 logical pixels.');
    }
    self::validateSigned32BitRange($layer);
  }

  /** Accept Camera floats before casting, so nonfinite/overflowing values cannot wrap into valid cells. */
  public static function validateSigned32BitRange(int|float $value): void
  {
    if (!is_finite($value) || $value < -2147483648 || $value > 2147483647) {
      throw new InvalidArgumentException('Sprite coordinates and layer must fit protocol-v1 signed 32-bit integers.');
    }
  }
}
