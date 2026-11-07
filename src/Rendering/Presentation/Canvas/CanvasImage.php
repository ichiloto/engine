<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
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
    public ?int $rotation = null,
    public ?PresentationSpritePivot $pivot = null,
    public ?CanvasImageBottomFade $bottomFade = null,
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
    if (($rotation === null) !== ($pivot === null) || ($rotation !== null && ($rotation < 0 || $rotation > 359))) {
      throw new InvalidArgumentException('Canvas image rotation requires integer degrees in 0..359 and a normalized pivot.');
    }
    if ($bottomFade !== null && $rotation !== null && $rotation !== 0) {
      throw new InvalidArgumentException('Canvas image bottom fade requires an axis-aligned destination.');
    }
    if ($rotation !== null && $rotation !== 0 && $sourceRect !== null) {
      self::assertUniformScale($destination, $sourceRect);
    }
  }

  /** Reads the canonical image descriptor through the same geometry and asset validation. */
  public static function fromArray(array $data): self
  {
    CanvasCompositeValues::validateKeys($data, ['id', 'asset', 'destination', 'layer'],
      ['sourceRect', 'opacity', 'clipRect', 'brightness', 'flipX', 'flipY', 'rotation', 'pivot', 'bottomFade']);
    if (!is_string($data['id']) || !is_string($data['asset']) || !is_int($data['layer'])) {
      throw new InvalidArgumentException('Canvas image id/asset require strings and layer requires an integer.');
    }
    foreach (['flipX', 'flipY'] as $key) {
      if (array_key_exists($key, $data) && !is_bool($data[$key])) { throw new InvalidArgumentException('Canvas image mirror flags must be boolean.'); }
    }
    $source = null;
    if (array_key_exists('sourceRect', $data)) {
      $rect = $data['sourceRect'];
      if (!is_array($rect) || array_diff(array_keys($rect), ['x', 'y', 'width', 'height']) !== []
        || count($rect) !== 4 || !array_all($rect, static fn($value): bool => is_int($value))) {
        throw new InvalidArgumentException('Canvas image sourceRect requires integer x/y/width/height.');
      }
      $source = new SpriteSourceRect($rect['x'], $rect['y'], $rect['width'], $rect['height']);
    }
    $rotation = $data['rotation'] ?? null;
    $pivot = $data['pivot'] ?? null;
    if ((array_key_exists('rotation', $data) && !is_int($rotation)) || (array_key_exists('pivot', $data) && !is_array($pivot))) {
      throw new InvalidArgumentException('Canvas image rotation and pivot require an integer and normalized coordinates.');
    }
    $clip = array_key_exists('clipRect', $data) ? new CanvasRectangle(...CanvasCompositeValues::getRectangle($data['clipRect'])) : null;
    $fade = $data['bottomFade'] ?? null;
    if (array_key_exists('bottomFade', $data) && !is_array($fade)) {
      throw new InvalidArgumentException('Canvas image bottom fade requires an object.');
    }
    $rect = $data['destination'];
    CanvasCompositeValues::validateKeys($rect, ['x', 'y', 'width', 'height']);
    $limit = CanvasValidation::MAX_EXTENT;
    $min = $rotation !== null && $clip !== null ? -$limit : 0;
    $destination = new CanvasRectangle(CanvasCompositeValues::getNumber($rect['x'], $min, $limit),
      CanvasCompositeValues::getNumber($rect['y'], $min, $limit), CanvasCompositeValues::getNumber($rect['width'], 0, $limit),
      CanvasCompositeValues::getNumber($rect['height'], 0, $limit), allowOutsideCanvas: $rotation !== null && $clip !== null);
    return new self($data['id'], $data['asset'], $destination, $data['layer'], $source,
      CanvasCompositeValues::getNumber($data['opacity'] ?? 1, 0, 1), $clip,
      CanvasCompositeValues::getNumber($data['brightness'] ?? 1, 0, 1), $data['flipX'] ?? false, $data['flipY'] ?? false,
      $rotation, $pivot === null ? null : PresentationSpritePivot::fromArray($pivot),
      $fade === null ? null : CanvasImageBottomFade::fromArray($fade));
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

  /** Keep the full source and pivot. The bounded viewport clips the rotated output. */
  public static function createRotated(string $id, string $asset, CanvasRectangle $destination,
    int $canvasWidth, int $canvasHeight, int $layer, SpriteSourceRect $sourceRect,
    bool $flipX, bool $flipY, int $rotation, PresentationSpritePivot $pivot): ?self
  {
    $bounds = self::measureRotation($destination, $rotation, $pivot, $sourceRect);
    [$left, $top, $right, $bottom] = $bounds;
    $x = max(0.0, $left);
    $y = max(0.0, $top);
    $right = min($canvasWidth, $right);
    $bottom = min($canvasHeight, $bottom);
    if ($right <= $x || $bottom <= $y) { return null; }
    $clip = $bounds == [$x, $y, $right, $bottom] && $destination->x >= 0 && $destination->y >= 0
      && $destination->x + $destination->width <= $canvasWidth && $destination->y + $destination->height <= $canvasHeight
      ? null : new CanvasRectangle($x, $y, $right - $x, $bottom - $y);
    return new self($id, $asset, $destination, $layer, $sourceRect, clipRect: $clip,
      flipX: $flipX, flipY: $flipY, rotation: $rotation, pivot: $pivot);
  }

  /** Unclipped placement of the prepared bitmap, including when the crop is the whole asset. */
  public function getPreparedDestination(int $sourceWidth, int $sourceHeight): CanvasRectangle
  {
    if ($this->rotation === null || $this->rotation === 0 || $this->pivot === null) { return $this->destination; }
    $crop = $this->sourceRect ?? new SpriteSourceRect(0, 0, $sourceWidth, $sourceHeight);
    self::assertUniformScale($this->destination, $crop);
    [$left, $top, $right, $bottom] = self::measureRotation($this->destination, $this->rotation, $this->pivot, $crop);
    return new CanvasRectangle($left, $top, $right - $left, $bottom - $top, allowOutsideCanvas: true);
  }

  private static function assertUniformScale(CanvasRectangle $destination, SpriteSourceRect $crop): void
  {
    if (min(abs($destination->width * $crop->height / $crop->width - $destination->height),
      abs($destination->height * $crop->width / $crop->height - $destination->width)) > 1.0 + 1e-9) {
      throw new InvalidArgumentException('Rotated canvas images require a uniform source-to-destination scale.');
    }
  }

  public function getPaintBounds(): CanvasRectangle
  {
    if ($this->clipRect !== null || $this->pivot === null || $this->rotation === null) {
      return $this->clipRect ?? $this->destination;
    }
    [$left, $top, $right, $bottom] = self::measureRotation($this->destination, $this->rotation, $this->pivot, $this->sourceRect);
    return new CanvasRectangle($left, $top, $right - $left, $bottom - $top);
  }

  public function assertWithin(int $width, int $height): void
  {
    if ($this->rotation === null || $this->clipRect === null) { $this->destination->assertWithin($width, $height); }
    $this->getPaintBounds()->assertWithin($width, $height);
  }

  /** @return array{float, float, float, float} */
  private static function measureRotation(CanvasRectangle $rect, int $rotation, PresentationSpritePivot $pivot,
    ?SpriteSourceRect $crop): array
  {
    if ($rotation === 0) { return [$rect->x, $rect->y, $rect->x + $rect->width, $rect->y + $rect->height]; }
    [$cos, $sin] = self::getRotationComponents($rotation);
    $pivotX = $rect->x + $rect->width * $pivot->x;
    $pivotY = $rect->y + $rect->height * $pivot->y;
    $width = $rect->width;
    $height = $rect->height;
    $paintWidth = abs($cos) * $width + abs($sin) * $height;
    $paintHeight = abs($sin) * $width + abs($cos) * $height;
    if ($crop !== null) {
      // The renderer prepares whole crop pixels and scales from the longer crop side.
      $scale = $crop->width >= $crop->height ? $width / $crop->width : $height / $crop->height;
      [$pixelWidth, $pixelHeight] = self::getRotatedPixelSize($crop->width, $crop->height, $rotation);
      $paintWidth = $pixelWidth * $scale;
      $paintHeight = $pixelHeight * $scale;
      $width = $crop->width * $scale;
      $height = $crop->height * $scale;
    }
    $offsetX = (.5 - $pivot->x) * $width;
    $offsetY = (.5 - $pivot->y) * $height;
    $centerX = $pivotX + $offsetX * $cos - $offsetY * $sin;
    $centerY = $pivotY + $offsetX * $sin + $offsetY * $cos;
    return [$centerX - $paintWidth / 2, $centerY - $paintHeight / 2,
      $centerX + $paintWidth / 2, $centerY + $paintHeight / 2];
  }

  /** Prepared regions have a two-pixel guard around the rotated interior. */
  public static function getRotatedRegionBytes(int $width, int $height, int $rotation): int
  {
    [$rotatedWidth, $rotatedHeight] = self::getRotatedPixelSize($width, $height, $rotation);
    return ($rotatedWidth + 4) * ($rotatedHeight + 4) * 4;
  }

  /** @return array{int, int} */
  public static function getRotatedPixelSize(int $width, int $height, int $rotation): array
  {
    [$cos, $sin] = self::getRotationComponents($rotation);
    return [max(1, (int)ceil($width * abs($cos) + $height * abs($sin) - 1e-9)),
      max(1, (int)ceil($width * abs($sin) + $height * abs($cos) - 1e-9))];
  }

  /** @return array{float, float} */
  private static function getRotationComponents(int $rotation): array
  {
    $angle = deg2rad($rotation);
    $cos = cos($angle);
    $sin = sin($angle);
    return $rotation % 90 === 0 ? [round($cos), round($sin)] : [$cos, $sin];
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
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()]),
      ...($this->rotation === null ? [] : ['rotation' => $this->rotation, 'pivot' => $this->pivot?->toArray()]),
      ...($this->bottomFade === null ? [] : ['bottomFade' => $this->bottomFade->toArray()])];
  }
}
