<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Source-pixel proportions are presentation intent, never a gameplay footprint. */
enum CanvasImageFit: string
{
  case STRETCH = 'stretch';
  case CONTAIN = 'contain';

  public static function parse(mixed $value): self
  {
    return is_string($value) && ($fit = self::tryFrom($value)) !== null ? $fit
      : throw new InvalidArgumentException('Image fit must be stretch or contain.');
  }

  /** @return array{width: float, height: float} */
  public function getSize(int $sourceWidth, int $sourceHeight, float $width, float $height): array
  {
    if ($sourceWidth < 1 || $sourceHeight < 1 || !is_finite($width) || !is_finite($height)
      || $width <= 0 || $height <= 0) {
      throw new InvalidArgumentException('Image fitting requires positive source dimensions and finite positive bounds.');
    }
    if ($this === self::CONTAIN) {
      $scale = min($width / $sourceWidth, $height / $sourceHeight);
      return ['width' => $sourceWidth * $scale, 'height' => $sourceHeight * $scale];
    }
    return ['width' => $width, 'height' => $height];
  }
}
