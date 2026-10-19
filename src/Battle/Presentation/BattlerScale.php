<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** A body unit relative to the project's reference actor, not an image or slot extent. */
final readonly class BattlerScale
{
  public function __construct(
    public float $relativeSize,
    public float $sourceSpan,
    public bool $horizontal = false,
  ) {
    self::validateUnit($relativeSize);
    self::validateUnit($sourceSpan);
  }

  /** Normalized calibration can exceed a cropped frame; it never measures the selected silhouette. */
  public static function validateUnit(float $value): void
  {
    if (!is_finite($value) || $value <= 0 || $value > 64) {
      throw new InvalidArgumentException('Battler body ratios and source units must be finite within 0..64.');
    }
  }

  public function getPixelScale(float $referenceHeight, BattlerArtwork $art, ?float $sourceSpan = null): float
  {
    if (!is_finite($referenceHeight) || $referenceHeight <= 0 || $referenceHeight > 16384) {
      throw new InvalidArgumentException('Reference body height must be finite within 0..16384.');
    }
    $span = $sourceSpan ?? $this->sourceSpan;
    self::validateUnit($span);
    return $referenceHeight * $this->relativeSize / ($span * ($this->horizontal ? $art->width : $art->height));
  }
}
