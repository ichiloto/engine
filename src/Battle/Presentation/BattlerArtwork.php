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

  /**
   * Reconciles this authored artwork with the image as it exists right now.
   *
   * Artwork changes throughout development, and the image on disk is this
   * moment's truth: supplying the right asset is the developer's job, and
   * the engine renders best-effort against any revision of it. A crop that
   * still overlaps the image clamps to it; a crop that no longer exists at
   * all falls back to the whole image, so the developer always sees their
   * art. The pivot clamps into whatever renders. Metadata that already fits
   * passes through as this same instance.
   *
   * @param int $imageWidth The image's current width in pixels.
   * @param int $imageHeight The image's current height in pixels.
   * @return self The artwork to render.
   */
  public function clampedTo(int $imageWidth, int $imageHeight): self
  {
    $authored = $this->sourceRect ?? new SpriteSourceRect(0, 0, $this->width, $this->height);

    if ($authored->x >= $imageWidth || $authored->y >= $imageHeight) {
      return new self(
        $this->asset,
        $imageWidth,
        $imageHeight,
        min($this->pivotX, (float) $imageWidth),
        min($this->pivotY, (float) $imageHeight),
        new SpriteSourceRect(0, 0, $imageWidth, $imageHeight),
      );
    }

    $width = min($authored->x + $authored->width, $imageWidth) - $authored->x;
    $height = min($authored->y + $authored->height, $imageHeight) - $authored->y;

    if ($width === $authored->width && $height === $authored->height) {
      return $this;
    }

    return new self(
      $this->asset,
      $width,
      $height,
      min($this->pivotX, (float) $width),
      min($this->pivotY, (float) $height),
      new SpriteSourceRect($authored->x, $authored->y, $width, $height),
    );
  }
}
