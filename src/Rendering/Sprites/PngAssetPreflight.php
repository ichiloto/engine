<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use RuntimeException;
use Closure;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Util\Debug;

/** Validates PNG sources and owns their file facts for one presentation inspection. */
final class PngAssetPreflight
{
  private const int MAX_CACHE_ENTRIES = 1024;
  /** The largest graphical asset file the Engine reads; tools that bring art into a project hold to it too. */
  public const int MAX_FILE_BYTES = 16 * 1024 * 1024;
  /** The widest and tallest graphical asset the Engine reads, in pixels. */
  public const int MAX_DIMENSION = 4096;
  /** @var array<string, array{version: array, size: ?array, error: ?string, reported: bool}> */
  private static array $cache = [];
  /** Current composition's file facts; discarded before the next frame or after any failure. */
  private static ?array $snapshot = null;

  /** Read each asset once while composing a consistent frame; nested consumers share its facts. */
  public static function runWithSnapshot(Closure $compose): mixed
  {
    if (self::$snapshot !== null) { return $compose(); }
    self::$snapshot = [];
    try { return $compose(); }
    finally { self::$snapshot = null; }
  }

  /**
   * Prepare a reading within one snapshot and retain versions for later consumption.
   * @template T
   * @param Closure(): T $read
   * @return array{0: T, 1: array<string, array{version: array, contentRevision?: string}>|null}
   */
  public static function captureReadVersions(string $root, Closure $read): array
  {
    return self::runWithSnapshot(static function () use ($root, $read): array {
      $result = $read();
      if (count(self::$snapshot) >= self::MAX_CACHE_ENTRIES) { return [$result, null]; }
      $prefix = $root . DIRECTORY_SEPARATOR;
      $versions = [];
      foreach (self::$snapshot as $key => $entry) {
        if (!str_starts_with($key, $prefix)) { continue; }
        $versions[substr($key, strlen($prefix))] = ['version' => $entry['version']];
        if (isset($entry['contentRevision'])) {
          $versions[substr($key, strlen($prefix))]['contentRevision'] = $entry['contentRevision'];
        }
      }
      return [$result, $versions];
    });
  }

  /** @param array<string, array{version: array, contentRevision?: string}> $versions */
  public static function hasReadVersions(string $root, array $versions): bool
  {
    return self::runWithSnapshot(static function () use ($root, $versions): bool {
      foreach ($versions as $asset => $expected) {
        $key = $root . DIRECTORY_SEPARATOR . $asset;
        $entry = self::$snapshot[$key] ?? self::inspectFile($root, $asset, $key);
        if (count(self::$snapshot) < self::MAX_CACHE_ENTRIES) { self::$snapshot[$key] = $entry; }
        if ($entry['version'] !== $expected['version']) { return false; }
        if (isset($expected['contentRevision'])) {
          try { $revision = self::getContentIdentity($root, $asset)['revision']; }
          catch (RuntimeException) { return false; }
          if ($revision !== $expected['contentRevision']) { return false; }
        }
      }
      return true;
    });
  }

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
    $entry = self::$snapshot[$key] ?? self::inspectFile($root, $asset, $key);
    if (self::$snapshot !== null && !isset(self::$snapshot[$key])) {
      if (count(self::$snapshot) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$snapshot); }
      self::$snapshot[$key] = $entry;
    }
    if ($entry['error'] !== null) { throw new RuntimeException($entry['error']); }
    $size = $entry['size'];
    if ($crop !== null) { self::assertRegionFits($asset, $size, $crop->x, $crop->y, $crop->width, $crop->height); }
    return ['width' => $crop?->width ?? $size['width'], 'height' => $crop?->height ?? $size['height']];
  }

  /** Fractional pixel views share the same current-file bounds and failure classification as integer crops.
   * @return array{width: float, height: float}
   */
  public static function inspectRegion(string $root, string $asset, float $x, float $y, float $width, float $height): array
  {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value)) { throw new \InvalidArgumentException('PNG source region values must be finite.'); }
    }
    if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0) {
      throw new \InvalidArgumentException('PNG source regions require nonnegative origins and positive extents.');
    }
    self::assertRegionFits($asset, self::inspect($root, $asset), $x, $y, $width, $height);
    return ['width' => $width, 'height' => $height];
  }

  /** File replacement can invalidate an otherwise valid authored crop; consumers report this resource failure.
   * @param array{width: int, height: int} $size
   */
  private static function assertRegionFits(string $asset, array $size, float $x, float $y, float $width, float $height): void
  {
    if ($x + $width > $size['width'] || $y + $height > $size['height']) {
      throw new RuntimeException("PNG dimensions or crop exceed image bounds: {$asset}");
    }
  }

  /** Current revision for callers that do not need the resolved path. */
  public static function getContentRevision(string $root, string $asset): string
  {
    return self::getContentIdentity($root, $asset)['revision'];
  }

  /** Current PNG path and revision, shared only until the owning composition ends.
   * @return array{path: string, revision: string}
   */
  public static function getContentIdentity(string $root, string $asset): array
  {
    self::inspect($root, $asset);
    $key = $root . DIRECTORY_SEPARATOR . $asset;
    $entry = self::$snapshot[$key] ?? self::$cache[$key];
    $path = $entry['version']['path'];
    if (isset($entry['contentRevision'])) { return ['path' => $path, 'revision' => $entry['contentRevision']]; }
    $span = LatencyTrace::beginSpan();
    try {
      clearstatcache(true, $path);
      if (realpath($path) !== $path || ($revision = @hash_file('xxh128', $path)) === false) {
        throw new RuntimeException('Visible-pixel inspection could not read the current PNG.');
      }
      if (self::$snapshot !== null) { self::$snapshot[$key]['contentRevision'] = $revision; }
    } finally { LatencyTrace::endSpan('png.content_revision', $span, ['asset' => $asset]); }
    return ['path' => $path, 'revision' => $revision];
  }

  /** Keep generated pixel data tied to the exact encoded revision, even during an external replacement. */
  public static function readRevisionBytes(string $root, string $asset, string $revision): string
  {
    self::inspect($root, $asset);
    $key = $root . DIRECTORY_SEPARATOR . $asset;
    $source = (self::$snapshot[$key] ?? self::$cache[$key])['version'];
    $path = $source['path'];
    clearstatcache(true, $path);
    $bytes = realpath($path) === $path && is_file($path)
      ? @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1) : false;
    if ($bytes === false || strlen($bytes) > self::MAX_FILE_BYTES
      || substr($bytes, 0, 24) !== $source['header'] || hash('xxh128', $bytes) !== $revision) {
      throw new RuntimeException("PNG asset changed during inspection: {$asset}");
    }
    return $bytes;
  }

  private static function inspectFile(string $root, string $asset, string $key): array
  {
    clearstatcache(true, $key);
    $canonicalRoot = realpath($root);
    $path = realpath($key);
    $stat = $path === false ? false : @stat($path);
    $header = null;
    $readError = null;
    // Stat timestamps can collide during in-place edits. Probe only the 24
    // header bytes owning dimensions, never the full image or decoded pixels.
    try { $header = self::readHeader($canonicalRoot, $path, $asset); }
    catch (RuntimeException $failure) { $readError = $failure->getMessage(); }
    $version = ['root' => $canonicalRoot, 'path' => $path, 'mtime' => $stat['mtime'] ?? null,
      'ctime' => $stat['ctime'] ?? null, 'size' => $stat['size'] ?? null, 'inode' => $stat['ino'] ?? null,
      'mode' => $stat['mode'] ?? null, 'header' => $header, 'readError' => $readError];
    if (!isset(self::$cache[$key]) || self::$cache[$key]['version'] !== $version) {
      if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$cache); }
      $size = null;
      $error = $readError;
      if ($error === null) {
        try { $size = self::readSize($header, $asset); }
        catch (RuntimeException $failure) { $error = $failure->getMessage(); }
      }
      self::$cache[$key] = ['version' => $version, 'size' => $size, 'error' => $error, 'reported' => false];
    }
    return self::$cache[$key];
  }

  /** Safe, bounded freshness probe shared by icons, frames, portraits and canvas budgets. */
  private static function readHeader(string|false $root, string|false $path, string $asset): string
  {
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png'
      || filesize($path) > self::MAX_FILE_BYTES) {
      throw new RuntimeException("Graphical asset must be a readable PNG inside assets, at most 16 MiB: {$asset}");
    }
    $header = @file_get_contents($path, false, null, 0, 24);
    if ($header === false) { throw new RuntimeException("Invalid PNG header: {$asset}"); }
    return $header;
  }

  /** Parsing and diagnostics are reused only while the current header and file facts agree. */
  private static function readSize(string $header, string $asset): array
  {
    if (strlen($header) !== 24 || substr($header, 0, 16) !== "\x89PNG\r\n\x1a\n\0\0\0\rIHDR") {
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
