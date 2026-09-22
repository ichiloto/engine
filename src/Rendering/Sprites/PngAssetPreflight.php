<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use RuntimeException;
use Ichiloto\Engine\Util\Debug;

/** Header/bounds preflight only. Native preparation remains the PNG decode authority. */
final class PngAssetPreflight
{
  private const int MAX_CACHE_ENTRIES = 1024;
  private const int MAX_FILE_BYTES = 16 * 1024 * 1024;
  private const int MAX_DIMENSION = 4096;
  /** @var array<string, array{version: array, size: ?array, error: ?string, reported: bool}> */
  private static array $cache = [];

  /** Optional artwork fails independently; report once per file revision, not once per frame. */
  public static function getAvailableSize(string $root, string $asset): ?array
  {
    try {
      return self::inspect($root, $asset);
    } catch (RuntimeException $error) {
      $key = $root . DIRECTORY_SEPARATOR . $asset;
      if (!(self::$cache[$key]['reported'] ?? false)) {
        Debug::warn($error->getMessage());
        if (isset(self::$cache[$key])) { self::$cache[$key]['reported'] = true; }
      }
      return null;
    }
  }

  /** @return array{width: int, height: int} Selected source dimensions. */
  public static function inspect(string $root, string $asset, ?SpriteSourceRect $crop = null): array
  {
    SpriteValidation::validateAssetPath($asset);
    $key = $root . DIRECTORY_SEPARATOR . $asset;
    clearstatcache(true, $key);
    $canonicalRoot = realpath($root);
    $path = realpath($key);
    $stat = $path === false ? false : @stat($path);
    $version = [$canonicalRoot, $path, $stat['mtime'] ?? null, $stat['ctime'] ?? null,
      $stat['size'] ?? null, $stat['ino'] ?? null, $stat['mode'] ?? null];
    if (!isset(self::$cache[$key]) || self::$cache[$key]['version'] !== $version) {
      if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$cache); }
      $size = null;
      $error = null;
      try { $size = self::readSize($canonicalRoot, $path, $asset); }
      catch (RuntimeException $failure) { $error = $failure->getMessage(); }
      self::$cache[$key] = ['version' => $version, 'size' => $size, 'error' => $error, 'reported' => false];
    }
    $entry = self::$cache[$key];
    if ($entry['error'] !== null) { throw new RuntimeException($entry['error']); }
    $size = $entry['size'];
    if ($crop !== null && ($crop->x + $crop->width > $size['width'] || $crop->y + $crop->height > $size['height'])) {
      throw new RuntimeException("PNG dimensions or crop exceed image bounds: {$asset}");
    }
    return ['width' => $crop?->width ?? $size['width'], 'height' => $crop?->height ?? $size['height']];
  }

  /** File reads are shared by icons, frames, portraits and canvas budget checks. */
  private static function readSize(string|false $root, string|false $path, string $asset): array
  {
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png'
      || filesize($path) > self::MAX_FILE_BYTES) {
      throw new RuntimeException("Graphical asset must be a readable PNG inside assets, at most 16 MiB: {$asset}");
    }
    $header = @file_get_contents($path, false, null, 0, 24);
    if ($header === false || strlen($header) !== 24 || substr($header, 0, 16) !== "\x89PNG\r\n\x1a\n\0\0\0\rIHDR") {
      throw new RuntimeException("Invalid PNG header: {$asset}");
    }
    $size = unpack('Nwidth/Nheight', substr($header, 16));
    if ($size === false || $size['width'] < 1 || $size['height'] < 1
      || $size['width'] > self::MAX_DIMENSION || $size['height'] > self::MAX_DIMENSION) {
      throw new RuntimeException("PNG dimensions or crop exceed image bounds: {$asset}");
    }
    return ['width' => $size['width'], 'height' => $size['height']];
  }
}
