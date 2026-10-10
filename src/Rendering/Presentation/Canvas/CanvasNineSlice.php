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
}
