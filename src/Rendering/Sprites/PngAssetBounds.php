<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;

/** Optional read-only author diagnostics; never a native PNG decode or gameplay gate. */
final class PngAssetBounds
{
  private const int MAX_CACHE_ENTRIES = 128;
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
      if ($path === false || $canonicalRoot === false || !str_starts_with($path, $canonicalRoot . DIRECTORY_SEPARATOR)
        || ($version = @hash_file('sha256', $path)) === false) {
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
        $left = $width;
        $top = $height;
        $right = $bottom = -1;
        $trueColor = imageistruecolor($decoded);
        $paletteAlpha = [];
        for ($row = 0; $row < $height; $row++) {
          for ($column = 0; $column < $width; $column++) {
            $color = imagecolorat($decoded, $x + $column, $y + $row);
            if ($color === false) { throw new RuntimeException('Visible-pixel inspection could not read a PNG pixel.'); }
            $alpha = $trueColor ? (($color >> 24) & 127)
              : ($paletteAlpha[$color] ??= imagecolorsforindex($decoded, $color)['alpha']);
            if ($alpha === 127) { continue; }
            $left = min($left, $column);
            $top = min($top, $row);
            $right = max($right, $column);
            $bottom = max($bottom, $row);
          }
        }
        unset($decoded);
        self::$cache[$key] = ['version' => $version,
          'pixels' => $right < 0 ? null : [$left, $top, $right - $left + 1, $bottom - $top + 1]];
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
}
