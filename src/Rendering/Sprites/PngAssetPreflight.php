<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use RuntimeException;

/** Header/bounds preflight only. Native preparation remains the PNG decode authority. */
final class PngAssetPreflight
{
  /** @return array{width: int, height: int} Selected source dimensions. */
  public static function inspect(string $root, string $asset, ?SpriteSourceRect $crop = null): array
  {
    SpriteValidation::validateAssetPath($asset);
    $root = realpath($root);
    $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $asset);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png'
      || filesize($path) > 16777216) {
      throw new RuntimeException("Graphical asset must be a readable PNG inside assets, at most 16 MiB: {$asset}");
    }
    $header = file_get_contents($path, false, null, 0, 24);
    if ($header === false || strlen($header) !== 24 || substr($header, 0, 16) !== "\x89PNG\r\n\x1a\n\0\0\0\rIHDR") {
      throw new RuntimeException("Invalid PNG header: {$asset}");
    }
    $size = unpack('Nwidth/Nheight', substr($header, 16));
    if ($size === false || $size['width'] < 1 || $size['height'] < 1 || $size['width'] > 4096 || $size['height'] > 4096
      || ($crop !== null && ($crop->x + $crop->width > $size['width'] || $crop->y + $crop->height > $size['height']))) {
      throw new RuntimeException("PNG dimensions or crop exceed image bounds: {$asset}");
    }
    return ['width' => $crop?->width ?? $size['width'], 'height' => $crop?->height ?? $size['height']];
  }
}
