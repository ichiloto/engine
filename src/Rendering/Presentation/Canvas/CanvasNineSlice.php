<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** Source-pixel cuts with shared destination edges; zero cuts also describe a plain image. */
final readonly class CanvasNineSlice
{
  public function __construct(
    public string $asset,
    public SpriteSourceRect $source,
    public int $left = 0,
    public int $top = 0,
    public int $right = 0,
    public int $bottom = 0,
    public int $density = 1,
    public float $minimumWidth = 0,
    public float $minimumHeight = 0,
  ) {
    SpriteValidation::validateAssetPath($asset);
    if (strlen($asset) > 4096 || !in_array($density, [1, 2], true)
      || min($left, $top, $right, $bottom) < 0
      || $left + $right > $source->width || $top + $bottom > $source->height
      || !is_finite($minimumWidth) || !is_finite($minimumHeight)
      || min($minimumWidth, $minimumHeight) < 0) {
      throw new InvalidArgumentException('Invalid image density, cuts or minimum dimensions.');
    }
  }

  /** Whole-file artwork needs a path and authored border intent, not duplicate image dimensions. */
  public static function getFromPng(string $root, string $asset, int $left = 0, int $top = 0,
    int $right = 0, int $bottom = 0, int $density = 1, float $minimumWidth = 0, float $minimumHeight = 0): self
  {
    if (min($left, $top, $right, $bottom) < 0) {
      throw new InvalidArgumentException('Image border cuts must be nonnegative.');
    }
    $size = PngAssetPreflight::inspect($root, $asset);
    $fit = static function (int $first, int $last, int $available): array {
      if ($first + $last <= $available) { return [$first, $last]; }
      $start = (int)floor($available * ($first / ((float)$first + $last)));
      return [$start, $available - $start];
    };
    // Keep a center pixel even when replacement artwork is smaller than the authored borders.
    [$l, $r] = $fit($left, $right, $size['width'] - 1);
    [$t, $b] = $fit($top, $bottom, $size['height'] - 1);
    if ([$l, $t, $r, $b] !== [$left, $top, $right, $bottom]) {
      Debug::warn("Image border cuts reconciled to the current PNG: {$asset}");
    }
    return new self($asset, new SpriteSourceRect(0, 0, $size['width'], $size['height']),
      $l, $t, $r, $b, $density, $minimumWidth, $minimumHeight);
  }

  /** @return list<CanvasImage> */
  public function images(string $id, CanvasRectangle $destination, int $layer, ?CanvasRectangle $clip = null): array
  {
    if ($destination->width < max($this->minimumWidth, ($this->left + $this->right) / $this->density)
      || $destination->height < max($this->minimumHeight, ($this->top + $this->bottom) / $this->density)) {
      throw new InvalidArgumentException('Destination is smaller than the image minimum or fixed corners.');
    }
    $sx = [$this->source->x, $this->source->x + $this->left,
      $this->source->x + $this->source->width - $this->right, $this->source->x + $this->source->width];
    $sy = [$this->source->y, $this->source->y + $this->top,
      $this->source->y + $this->source->height - $this->bottom, $this->source->y + $this->source->height];
    $dx = [$destination->x, $destination->x + $this->left / $this->density,
      $destination->x + $destination->width - $this->right / $this->density, $destination->x + $destination->width];
    $dy = [$destination->y, $destination->y + $this->top / $this->density,
      $destination->y + $destination->height - $this->bottom / $this->density, $destination->y + $destination->height];
    $images = [];
    for ($row = 0; $row < 3; $row++) {
      for ($column = 0; $column < 3; $column++) {
        if ($sx[$column + 1] <= $sx[$column] || $sy[$row + 1] <= $sy[$row]
          || $dx[$column + 1] <= $dx[$column] || $dy[$row + 1] <= $dy[$row]) { continue; }
        $images[] = new CanvasImage("{$id}-{$row}-{$column}", $this->asset,
          new CanvasRectangle($dx[$column], $dy[$row], $dx[$column + 1] - $dx[$column], $dy[$row + 1] - $dy[$row]),
          $layer, new SpriteSourceRect($sx[$column], $sy[$row], $sx[$column + 1] - $sx[$column], $sy[$row + 1] - $sy[$row]),
          clipRect: $clip);
      }
    }
    return $images;
  }

  /**
   * Repeat a full-height middle sample horizontally without stretching source pixels.
   * Height must equal source height / density; existing vertical cuts remain fixed.
   * The final image keeps its full sample size and clips its fractional tail, so its
   * original destination must still fit the canvas. Budget counts planned pieces
   * before clipping, including caps, and must be the caller's remaining image slots.
   * @return list<CanvasImage>
   */
  public function composeRepeatedXImages(string $id, CanvasRectangle $destination, int $layer,
    ?CanvasRectangle $clip = null, ?SpriteSourceRect $repeatSource = null, int $remainingImageBudget = 1024): array
  {
    $middleLeft = $this->source->x + $this->left;
    $middleRight = $this->source->x + $this->source->width - $this->right;
    if ($middleRight <= $middleLeft) {
      throw new InvalidArgumentException('Horizontal repetition requires a positive source middle strip.');
    }
    $repeatSource ??= new SpriteSourceRect($middleLeft, $this->source->y,
      $middleRight - $middleLeft, $this->source->height);
    if ($repeatSource->x < $middleLeft || $repeatSource->x + $repeatSource->width > $middleRight
      || $repeatSource->y !== $this->source->y || $repeatSource->height !== $this->source->height) {
      throw new InvalidArgumentException('Repeat source must be a full-height crop wholly within the middle strip.');
    }
    if ($destination->width < max($this->minimumWidth, ($this->left + $this->right) / $this->density)
      || $destination->height < $this->minimumHeight
      || $destination->height !== (float)$this->source->height / $this->density) {
      throw new InvalidArgumentException('Repeated destination must fit the minimum and fixed caps at the exact source height / density.');
    }
    if ($remainingImageBudget < 0 || $remainingImageBudget > 1024) {
      throw new InvalidArgumentException('Remaining canvas image budget must be in 0..1024.');
    }
    $sx = [$this->source->x, $middleLeft, $middleRight, $this->source->x + $this->source->width];
    $sy = [$this->source->y, $this->source->y + $this->top,
      $this->source->y + $this->source->height - $this->bottom, $this->source->y + $this->source->height];
    $dx = [$destination->x, $destination->x + $this->left / $this->density,
      $destination->x + $destination->width - $this->right / $this->density, $destination->x + $destination->width];
    $dy = [$destination->y, $destination->y + $this->top / $this->density,
      $destination->y + $destination->height - $this->bottom / $this->density, $destination->y + $destination->height];
    $repeatWidth = $repeatSource->width / $this->density;
    $repeatCount = (int)ceil(max(0, $dx[2] - $dx[1]) / $repeatWidth);
    $rowCount = 0;
    for ($row = 0; $row < 3; $row++) {
      if ($sy[$row + 1] > $sy[$row]) { $rowCount++; }
    }
    $pieceCount = $rowCount * ((int)($this->left > 0) + $repeatCount + (int)($this->right > 0));
    // Refuse expansion before constructing any CanvasImage or allocating its list.
    if ($pieceCount > $remainingImageBudget) {
      throw new InvalidArgumentException('Horizontal repeat expansion exceeds the remaining canvas image budget.');
    }
    $tailClip = null;
    $hasTail = $repeatCount > 0 && $dx[1] + $repeatCount * $repeatWidth > $dx[2];
    if ($hasTail) {
      $x = $dx[1] + ($repeatCount - 1) * $repeatWidth;
      $y = $destination->y;
      $right = $dx[2];
      $bottom = $dy[3];
      if ($clip !== null) {
        $x = max($x, $clip->x);
        $y = max($y, $clip->y);
        $right = min($right, $clip->x + $clip->width);
        $bottom = min($bottom, $clip->y + $clip->height);
      }
      if ($right > $x && $bottom > $y) {
        $tailClip = new CanvasRectangle($x, $y, $right - $x, $bottom - $y);
      }
    }
    $images = [];
    for ($row = 0; $row < 3; $row++) {
      if ($sy[$row + 1] <= $sy[$row]) { continue; }
      for ($column = 0; $column < 3; $column++) {
        $count = $column === 1 ? $repeatCount : (int)($sx[$column + 1] > $sx[$column]);
        for ($index = 0; $index < $count; $index++) {
          $isTail = $column === 1 && $hasTail && $index === $repeatCount - 1;
          if ($isTail && $tailClip === null) { continue; }
          $x = $column === 1 ? $dx[1] + $index * $repeatWidth : $dx[$column];
          $width = $column === 1 ? $repeatWidth : ($sx[$column + 1] - $sx[$column]) / $this->density;
          $sourceX = $column === 1 ? $repeatSource->x : $sx[$column];
          $sourceWidth = $column === 1 ? $repeatSource->width : $sx[$column + 1] - $sx[$column];
          $pieceId = "{$id}-{$row}-{$column}" . ($column === 1 ? "-{$index}" : '');
          $images[] = new CanvasImage($pieceId, $this->asset,
            new CanvasRectangle($x, $dy[$row], $width, ($sy[$row + 1] - $sy[$row]) / $this->density),
            $layer, new SpriteSourceRect($sourceX, $sy[$row], $sourceWidth, $sy[$row + 1] - $sy[$row]),
            clipRect: $isTail ? $tailClip : $clip);
        }
      }
    }
    return $images;
  }
}
