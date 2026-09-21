<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** Authored border cuts/density. The complete source rectangle always comes from the current PNG. */
final readonly class MenuRowArtwork
{
  public function __construct(public string $asset, public int $left = 0, public int $top = 0,
    public int $right = 0, public int $bottom = 0, public int $density = 1,
    public ?array $borderWidths = null)
  {
    SpriteValidation::validateAssetPath($asset);
    if (strlen($asset) > 4096 || min($left, $top, $right, $bottom) < 0 || !in_array($density, [1, 2], true)) {
      throw new InvalidArgumentException('Menu artwork requires nonnegative cuts and a supported PNG density.');
    }
    if ($borderWidths !== null && (!array_is_list($borderWidths) || count($borderWidths) !== 4
      || !array_all($borderWidths, static fn($value) => is_int($value) && $value >= 0))) {
      throw new InvalidArgumentException('Menu border widths must be four nonnegative source-pixel integers.');
    }
  }

  /** @return list<CanvasImage> */
  public function images(string $root, string $id, CanvasRectangle $bounds, int $layer, ?int $borderLayer = null): array
  {
    $images = $this->slice($root, $bounds)->images($id, $bounds, $layer, $bounds);
    if ($borderLayer === null) { return $images; }
    $result = [];
    foreach ($images as $image) {
      $cell = substr($image->id, strlen($id) + 1);
      if ($cell === '1-1') { $result[] = $image; continue; }
      $side = array_search($cell, ['1-0', '0-1', '1-2', '2-1'], true);
      if ($this->borderWidths === null || $side === false) {
        $result[] = new CanvasImage($image->id, $image->asset, $image->destination, $borderLayer,
          $image->sourceRect, $image->opacity, $image->clipRect);
        continue;
      }
      // Partition each straight edge once: inner backing below selection, decoration above it.
      // Disjoint clips avoid double-compositing translucent replacement artwork.
      $d = $image->destination;
      $horizontal = $side === 1 || $side === 3;
      $extent = $horizontal ? $d->height : $d->width;
      $border = min($extent, $this->borderWidths[$side] / $this->density);
      $start = $side < 2;
      foreach ([true, false] as $outer) {
        $size = $outer ? $border : $extent - $border;
        if ($size <= 0) { continue; }
        $offset = $outer ? ($start ? 0 : $extent - $border) : ($start ? $border : 0);
        $clip = new CanvasRectangle($d->x + ($horizontal ? 0 : $offset), $d->y + ($horizontal ? $offset : 0),
          $horizontal ? $d->width : $size, $horizontal ? $size : $d->height);
        $result[] = new CanvasImage($image->id . ($outer ? '-border' : '-backing'), $image->asset,
          $d, $outer ? $borderLayer : $layer, $image->sourceRect, $image->opacity, $clip);
      }
    }
    return $result;
  }

  /** Effective straight-edge insets, after current-image reconciliation and density scaling.
   * @return null|array{float, float, float, float}
   */
  public function borderInsets(string $root, CanvasRectangle $bounds): ?array
  {
    if ($this->borderWidths === null) { return null; }
    $slice = $this->slice($root, $bounds);
    return array_map(fn(int $width, int $cut): float => min($width, $cut) / $this->density,
      $this->borderWidths, [$slice->left, $slice->top, $slice->right, $slice->bottom]);
  }

  private function slice(string $root, CanvasRectangle $bounds): CanvasNineSlice
  {
    // Row geometry constrains authored cuts; the shared loader reconciles current source facts.
    [$left, $right] = $this->fitCuts($this->left, $this->right, (int)floor($bounds->width * $this->density));
    [$top, $bottom] = $this->fitCuts($this->top, $this->bottom, (int)floor($bounds->height * $this->density));
    if ([$left, $top, $right, $bottom] !== [$this->left, $this->top, $this->right, $this->bottom]) {
      Debug::warn("Menu artwork cuts reconciled to current image and row bounds: {$this->asset}");
    }
    return CanvasNineSlice::fromPng($root, $this->asset, $left, $top, $right, $bottom, $this->density);
  }

  /** @return array{int, int} */
  private function fitCuts(int $first, int $last, int $available): array
  {
    if ($first + $last <= $available) { return [$first, $last]; }
    $start = (int)floor($available * ($first / ((float)$first + $last)));
    return [$start, $available - $start];
  }
}
