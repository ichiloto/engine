<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Dimensions and pivot are pixels relative to the selected crop (or whole PNG). */
final readonly class BattlerArtwork
{
  public function __construct(
    public string $asset,
    public int $width,
    public int $height,
    public float $pivotX,
    public float $pivotY,
    public ?SpriteSourceRect $sourceRect = null,
  ) {
    SpriteValidation::validateDefinition($asset, $width, $height, 0);
    if (strlen($asset) > 4096 || !is_finite($pivotX) || !is_finite($pivotY)
      || $pivotX < 0 || $pivotY < 0 || $pivotX > $width || $pivotY > $height
      || ($sourceRect !== null && ($sourceRect->width !== $width || $sourceRect->height !== $height))) {
      throw new InvalidArgumentException('Artwork dimensions must match its crop and contain its finite pivot.');
    }
  }
}
