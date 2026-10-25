<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;

/**
 * The visible extent of current artwork as drawn: what battle overlays attach to and what author
 * diagnostics measure. Read-only and presentation-only; never a native PNG decode or gameplay gate.
 */
final class PngAssetBounds
{
  private const int MAX_CACHE_ENTRIES = 512;
  /** @var array<string, array{version: string, pixels: ?array}> */
  private static array $cache = [];

  /** @return array{bounds: ?CanvasRectangle, diagnostic: ?string} */
  public static function getVisibleBounds(string $root, CanvasImage $image): array
  {
    try {
      $size = PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect);
      if (!function_exists('imagecreatefrompng')) {
        throw new RuntimeException('Visible-pixel inspection unavailable: optional GD PNG support is not installed.');
      }
      $path = realpath($root . DIRECTORY_SEPARATOR . $image->asset);
      $canonicalRoot = realpath($root);
      // A content hash, not stat facts, so a same-size in-place edit is never served stale; xxh128 keeps it cheap per frame.
      if ($path === false || $canonicalRoot === false || !str_starts_with($path, $canonicalRoot . DIRECTORY_SEPARATOR)
        || ($version = @hash_file('xxh128', $path)) === false) {
        throw new RuntimeException('Visible-pixel inspection could not read the current PNG.');
      }
      $crop = $image->sourceRect;
      $key = $path . ':' . serialize($crop);
      if (!isset(self::$cache[$key]) || self::$cache[$key]['version'] !== $version) {
        if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$cache); }
        $decoded = @imagecreatefrompng($path);
        if ($decoded === false) { throw new RuntimeException('Visible-pixel inspection could not decode the current PNG.'); }
        $x = $crop?->x ?? 0;
        $y = $crop?->y ?? 0;
        $width = $size['width'];
        $height = $size['height'];
        if ($x + $width > imagesx($decoded) || $y + $height > imagesy($decoded)) {
          throw new RuntimeException('Visible-pixel crop exceeds the decoded PNG.');
        }
        // Palette transparency becomes the alpha channel, so one comparison classifies every pixel.
        if (!imageistruecolor($decoded)) { imagepalettetotruecolor($decoded); }
        $pixels = self::findVisiblePixels($decoded, $x, $y, $width, $height);
        unset($decoded);
        self::$cache[$key] = ['version' => $version, 'pixels' => $pixels];
      }
      $pixels = self::$cache[$key]['pixels'];
      if ($pixels === null || $image->opacity === 0.0) { return ['bounds' => null, 'diagnostic' => null]; }
      [$left, $top, $width, $height] = $pixels;
      if ($image->flipX) { $left = $size['width'] - $left - $width; }
      if ($image->flipY) { $top = $size['height'] - $top - $height; }
      $destination = $image->destination;
      $x = $destination->x + $left * $destination->width / $size['width'];
      $y = $destination->y + $top * $destination->height / $size['height'];
      $right = $x + $width * $destination->width / $size['width'];
      $bottom = $y + $height * $destination->height / $size['height'];
      if ($image->clipRect !== null) {
        $x = max($x, $image->clipRect->x);
        $y = max($y, $image->clipRect->y);
        $right = min($right, $image->clipRect->x + $image->clipRect->width);
        $bottom = min($bottom, $image->clipRect->y + $image->clipRect->height);
      }
      return ['bounds' => $right <= $x || $bottom <= $y ? null : new CanvasRectangle($x, $y, $right - $x, $bottom - $y),
        'diagnostic' => null];
    } catch (RuntimeException $error) {
      return ['bounds' => null, 'diagnostic' => $error->getMessage()];
    }
  }
  /**
   * Scans inward from each edge and stops at the first visible pixel, so padded artwork reads only its margins.
   * @return array{int, int, int, int}|null Left, top, width and height within the crop; null when fully transparent.
   */
  private static function findVisiblePixels(\GdImage $image, int $x, int $y, int $width, int $height): ?array
  {
    $top = $bottom = $left = $right = null;
    for ($row = 0; $row < $height && $top === null; $row++) {
      for ($column = 0; $column < $width; $column++) {
        if (imagecolorat($image, $x + $column, $y + $row) < 0x7F000000) { $top = $row; break; }
      }
    }
    if ($top === null) { return null; }
    for ($row = $height - 1; $bottom === null; $row--) {
      for ($column = 0; $column < $width; $column++) {
        if (imagecolorat($image, $x + $column, $y + $row) < 0x7F000000) { $bottom = $row; break; }
      }
    }
    for ($column = 0; $left === null; $column++) {
      for ($row = $top; $row <= $bottom; $row++) {
        if (imagecolorat($image, $x + $column, $y + $row) < 0x7F000000) { $left = $column; break; }
      }
    }
    for ($column = $width - 1; $right === null; $column--) {
      for ($row = $top; $row <= $bottom; $row++) {
        if (imagecolorat($image, $x + $column, $y + $row) < 0x7F000000) { $right = $column; break; }
      }
    }
    return [$left, $top, $right - $left + 1, $bottom - $top + 1];
  }
}
