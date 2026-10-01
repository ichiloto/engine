<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** Dimensions and pivot are pixels relative to the selected crop (or whole PNG). */
final readonly class BattlerArtwork
{
  /** Whole-image pivots are normalized authoring intent, never duplicate PNG dimensions. */
  public static function getFromPng(string $assetRoot, string $asset, float $pivotX = 0.5, float $pivotY = 1.0): self
  {
    if (!is_finite($pivotX) || !is_finite($pivotY) || min($pivotX, $pivotY) < 0 || max($pivotX, $pivotY) > 1) {
      throw new InvalidArgumentException('Whole-image pivots must be finite normalized coordinates.');
    }
    // An unavailable optional image keeps its identity and pivot intent for its per-battler fallback.
    $size = PngAssetPreflight::getAvailableSize($assetRoot, $asset) ?? ['width' => 1, 'height' => 1];
    return new self($asset, $size['width'], $size['height'], $pivotX * $size['width'], $pivotY * $size['height']);
  }

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
   * art. Cropped pivots clamp into whatever renders. Whole-image pivots
   * retain their normalized position as the complete image changes size.
   * Metadata that already fits passes through as this same instance.
   *
   * @param int $imageWidth The image's current width in pixels.
   * @param int $imageHeight The image's current height in pixels.
   * @return self The artwork to render.
   */
  public function clampedTo(int $imageWidth, int $imageHeight): self
  {
    if ($this->sourceRect === null) {
      return $imageWidth === $this->width && $imageHeight === $this->height ? $this : new self(
        $this->asset, $imageWidth, $imageHeight,
        $this->pivotX / $this->width * $imageWidth, $this->pivotY / $this->height * $imageHeight,
      );
    }
    $authored = $this->sourceRect;

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
