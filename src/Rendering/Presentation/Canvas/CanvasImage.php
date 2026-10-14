<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

final readonly class CanvasImage
{
  public function __construct(
    public string $id,
    public string $asset,
    public CanvasRectangle $destination,
    public int $layer = 0,
    public ?SpriteSourceRect $sourceRect = null,
    public float $opacity = 1,
    public ?CanvasRectangle $clipRect = null,
    public float $brightness = 1,
    public bool $flipX = false,
    public bool $flipY = false,
  )
  {
    CanvasValidation::id($id);
    SpriteValidation::validateAssetPath($asset);
    SpriteValidation::validateSigned32BitRange($layer);
    if (strlen($asset) > 4096 || !is_finite($opacity) || $opacity < 0 || $opacity > 1) {
      throw new InvalidArgumentException('Canvas assets are limited to 4096 bytes and opacity to finite values in 0..1.');
    }
    if (!is_finite($brightness) || $brightness < 0 || $brightness > 1) {
      throw new InvalidArgumentException('Canvas image brightness must be finite and in 0..1.');
    }
  }

  /** Clip source pixels at the viewport edge without moving the artwork's attachment point. */
  public static function createClipped(string $id, string $asset, float $x, float $y, float $width, float $height,
    int $canvasWidth, int $canvasHeight, int $layer, SpriteSourceRect $sourceRect,
    bool $flipX = false, bool $flipY = false): ?self
  {
    if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)
      || $width <= 0 || $height <= 0 || $width > CanvasValidation::MAX_EXTENT || $height > CanvasValidation::MAX_EXTENT
      || $canvasWidth < 1 || $canvasHeight < 1 || $canvasWidth > CanvasValidation::MAX_EXTENT
      || $canvasHeight > CanvasValidation::MAX_EXTENT) {
      throw new InvalidArgumentException('Clipped image placement requires finite origins, positive extents and a bounded canvas.');
    }
    if ($x >= $canvasWidth || $y >= $canvasHeight || $x + $width <= 0 || $y + $height <= 0) { return null; }
    $scaleX = $width / $sourceRect->width;
    $scaleY = $height / $sourceRect->height;
    $left = (int)ceil(max(0, -$x) / $scaleX);
    $top = (int)ceil(max(0, -$y) / $scaleY);
    $right = (int)ceil(max(0, $x + $width - $canvasWidth) / $scaleX);
    $bottom = (int)ceil(max(0, $y + $height - $canvasHeight) / $scaleY);
    $pixelsWide = $sourceRect->width - $left - $right;
    $pixelsHigh = $sourceRect->height - $top - $bottom;
    if ($pixelsWide < 1 || $pixelsHigh < 1) { return null; }
    // Resolve bounded pixel edges together; separately scaled extents can round past the viewport.
    $destinationX = max(0, $x + $left * $scaleX);
    $destinationY = max(0, $y + $top * $scaleY);
    $destinationRight = min($canvasWidth, $x + ($sourceRect->width - $right) * $scaleX);
    $destinationBottom = min($canvasHeight, $y + ($sourceRect->height - $bottom) * $scaleY);
    if ($destinationRight <= $destinationX || $destinationBottom <= $destinationY) { return null; }
    return new self($id, $asset,
      new CanvasRectangle($destinationX, $destinationY, $destinationRight - $destinationX, $destinationBottom - $destinationY),
      $layer, new SpriteSourceRect($sourceRect->x + ($flipX ? $right : $left),
        $sourceRect->y + ($flipY ? $bottom : $top), $pixelsWide, $pixelsHigh), flipX: $flipX, flipY: $flipY);
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'asset' => $this->asset, 'destination' => $this->destination->toArray(),
      'layer' => $this->layer,
      ...($this->sourceRect === null ? [] : ['sourceRect' => $this->sourceRect->toArray()]),
      ...($this->opacity === 1.0 ? [] : ['opacity' => $this->opacity]),
      ...($this->brightness === 1.0 ? [] : ['brightness' => $this->brightness]),
      ...($this->flipX ? ['flipX' => true] : []),
      ...($this->flipY ? ['flipY' => true] : []),
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()])];
  }
}
