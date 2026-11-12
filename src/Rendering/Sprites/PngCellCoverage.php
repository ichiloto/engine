<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use RuntimeException;

/**
 * Which pixels of each map cell a field image covers with opaque art, read
 * from the image as it is now. The image is placed as the field draws a world
 * object: its pivot on the anchor cell's ground point, raised by its lift and
 * stretched to its size in field cells. Nothing is stored, so replacing the
 * file needs no metadata; each call reads the current PNG.
 *
 * Optional author diagnostics only: the game never reads it. Reading needs
 * GD; without it, or for an image that cannot be read, the answer says why.
 */
final class PngCellCoverage
{
  /** Opacity, out of 127, at which a pixel counts as art rather than haze or a painted shadow's edge. */
  public const int OPAQUE = 64;

  private function __construct()
  {
  }

  /**
   * @return array{cells: array<int, array<int, string>>, diagnostic: ?string} For every map cell the art reaches, by
   *     row then column, one byte per field pixel in row-major order: "\1" where the art is opaque, "\0" elsewhere.
   */
  public static function measure(string $root, GraphicalSpriteDefinition $frame, int $anchorX, int $anchorY): array
  {
    try {
      if ($frame->quarterTurns !== 0) {
        throw new RuntimeException('turned art cannot be measured cell by cell.');
      }
      if (!function_exists('imagecreatefrompng')) {
        throw new RuntimeException('optional GD PNG support is not installed.');
      }
      $size = PngAssetPreflight::inspect($root, $frame->asset, $frame->sourceRect);
      $image = @imagecreatefrompng($root . DIRECTORY_SEPARATOR . $frame->asset);
      if ($image === false) {
        throw new RuntimeException("{$frame->asset} could not be decoded.");
      }
      [$sourceX, $sourceY] = [$frame->sourceRect?->x ?? 0, $frame->sourceRect?->y ?? 0];
      [$sourceWidth, $sourceHeight] = [$size['width'], $size['height']];
      if ($sourceX + $sourceWidth > imagesx($image) || $sourceY + $sourceHeight > imagesy($image)) {
        throw new RuntimeException("the crop of {$frame->asset} exceeds the image.");
      }
      $tile = FieldViewport::TILE_SIZE;
      $pivot = $frame->pivot ?? new PresentationSpritePivot();
      $left = (int) round(($anchorX + .5) * $tile - $pivot->x * $frame->width);
      $top = (int) round(($anchorY + 1) * $tile - $frame->lift - $pivot->y * $frame->height);
      $trueColor = imageistruecolor($image);
      $paletteAlpha = [];
      $opaque = [];
      // Each source pixel is read once, then every field pixel it stretches over takes its verdict.
      for ($y = 0; $y < $frame->height; $y++) {
        $row = $sourceY + intdiv($y * $sourceHeight, $frame->height);
        $fieldY = $top + $y;
        $cellY = intdiv($fieldY - self::floorMod($fieldY, $tile), $tile);
        $localY = self::floorMod($fieldY, $tile);
        for ($x = 0; $x < $frame->width; $x++) {
          $column = $sourceX + intdiv($x * $sourceWidth, $frame->width);
          if (!($opaque[$row][$column] ??= self::isOpaque($image, $column, $row, $trueColor, $paletteAlpha))) { continue; }
          $fieldX = $left + $x;
          $localX = self::floorMod($fieldX, $tile);
          $cellX = intdiv($fieldX - $localX, $tile);
          $cells[$cellY][$cellX] ??= str_repeat("\0", $tile * $tile);
          $cells[$cellY][$cellX][$localY * $tile + $localX] = "\1";
        }
      }
      unset($image);
      return ['cells' => $cells ?? [], 'diagnostic' => null];
    } catch (RuntimeException $error) {
      return ['cells' => [], 'diagnostic' => $error->getMessage()];
    }
  }

  /**
   * Whether art stands in the middle of the cell, where the player stops
   * when it blocks them: opaque pixels in the cell's central band of columns
   * on at least a quarter of its rows. A post or trunk does, however thin; a
   * sliver along the cell's edge does not.
   */
  public static function isCentreCovered(?string $mask): bool
  {
    if ($mask === null) {
      return false;
    }
    $tile = (int) sqrt(strlen($mask));
    [$from, $width] = [intdiv($tile * 3, 8), intdiv($tile, 4)];
    $rows = 0;
    for ($y = 0; $y < $tile; $y++) {
      if (str_contains(substr($mask, $y * $tile + $from, $width), "\1")) { $rows++; }
    }
    return $rows * 4 >= $tile;
  }

  /** The share of a cell's pixels a mask covers, from 0 to 1. */
  public static function getShare(?string $mask): float
  {
    return $mask === null ? 0.0 : substr_count($mask, "\1") / strlen($mask);
  }

  /** @param array<int, int> $paletteAlpha Alpha by palette index, filled as indices are met. */
  private static function isOpaque(\GdImage $image, int $x, int $y, bool $trueColor, array &$paletteAlpha): bool
  {
    $color = imagecolorat($image, $x, $y);
    if ($color === false) { throw new RuntimeException('a pixel could not be read.'); }
    $alpha = $trueColor ? (($color >> 24) & 127) : ($paletteAlpha[$color] ??= imagecolorsforindex($image, $color)['alpha']);
    return 127 - $alpha >= self::OPAQUE;
  }

  private static function floorMod(int $value, int $divisor): int
  {
    return (($value % $divisor) + $divisor) % $divisor;
  }
}
