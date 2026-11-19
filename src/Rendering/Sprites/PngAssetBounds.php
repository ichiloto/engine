<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;
use GdImage;
use Ichiloto\Engine\Diagnostics\LatencyTrace;

/**
 * What current artwork shows, read from its pixels once per content revision and crop: its visible extent as drawn,
 * which battle overlays attach to and author diagnostics measure, and where it meets the ground, which its shadow
 * lies under. Replacing the image needs no authored metadata. Source pixels are read-only; exact generated facts
 * are disposable cached presentation data, never a gameplay gate.
 */
final class PngAssetBounds
{
  private const int MAX_CACHE_ENTRIES = 512;
  /** Opacity, out of 127, from which a pixel is solid art rather than haze, glow or a soft edge: what stands on the ground. */
  private const int SOLID = 64;
  /** How much of the art, up from its lowest solid row, is searched for its lower edge. */
  private const float EDGE_SEARCH = .6;
  /** Gaps in a ground contact narrower than this share of the art's height do not split it. */
  private const float JOIN_GAP = .02;
  /** The longest piece a ground contact is returned in, as a share of the art's height. */
  private const float PIECE = .08;
  /** @var array<string, array{version: string, pixels: ?array, edge: array<int, int>}> */
  private static array $cache = [];
  private static ?PngAssetFactsCache $factsCache = null;
  private static bool $factsCacheResolved = false;

  /** Explicit host/test storage ownership; changing stores discards only process-local generated facts. */
  public static function setFactsCache(PngAssetFactsCache $cache): void
  {
    self::$factsCache = $cache;
    self::$factsCacheResolved = true;
    self::$cache = [];
  }


  public static function getFactsCache(): ?PngAssetFactsCache
  {
    if (!self::$factsCacheResolved) {
      self::$factsCacheResolved = true;
      try { self::$factsCache = PngAssetFactsCache::createForUser(); }
      catch (RuntimeException|\InvalidArgumentException $failure) {
        \Ichiloto\Engine\Util\Debug::warn('Generated PNG facts cache location unavailable: ' . $failure->getMessage());
      }
    }
    return self::$factsCache;
  }

  /** Check current revisions without decoding a missing crop or writing generated data.
   * @param list<CanvasImage> $images
   */
  public static function hasCurrentFacts(string $root, array $images, bool $memoryOnly = false): bool
  {
    return PngAssetPreflight::runWithSnapshot(static function () use ($root, $images, $memoryOnly): bool {
      foreach ($images as $image) {
        if (self::readInSnapshot($root, $image, allowGeneration: false, allowStoreRead: !$memoryOnly) === null) {
          return false;
        }
      }
      return true;
    });
  }

  /**
   * Load validated generated records up to the caller's monotonic deadline.
   * The returned index is the next crop; null reports a missing current revision.
   * @param list<CanvasImage> $images
   */
  public static function hydrateCurrentFacts(string $root, array $images, int $from, int $deadline): ?int
  {
    return PngAssetPreflight::runWithSnapshot(static function () use ($root, $images, $from, $deadline): ?int {
      $end = count($images);
      if ($from < 0 || $from > $end) {
        throw new \InvalidArgumentException('Sprite facts hydration offset must be within the crop list.');
      }
      for ($index = $from; $index < $end; $index++) {
        if (self::readInSnapshot($root, $images[$index], allowGeneration: false) === null) { return null; }
        if (hrtime(true) >= $deadline) { return $index + 1; }
      }
      return $end;
    });
  }

  /** @return array{bounds: ?CanvasRectangle, diagnostic: ?string} */
  public static function getVisibleBounds(string $root, CanvasImage $image): array
  {
    try {
      [$size, $facts] = self::read($root, $image);
      return self::projectVisibleBounds($image, $size, $facts);
    } catch (RuntimeException $error) {
      return ['bounds' => null, 'diagnostic' => $error->getMessage()];
    }
  }

  /**
   * Inspect a set of drawings with one transient PNG decode per asset that has uncached crops.
   * Only compact crop facts survive the call. Each file's decoded pixels are released before the next file.
   * The owning content snapshot keeps replacement detection consistent across all crops in the batch.
   *
   * @param list<CanvasImage> $images
   * @return list<array{bounds: ?CanvasRectangle, diagnostic: ?string}> Results in input order.
   */
  public static function getVisibleBoundsForImages(string $root, array $images): array
  {
    if ($images === []) { return []; }
    $scan = static fn(): array => PngAssetPreflight::runWithSnapshot(static function () use ($root, $images): array {
      $groups = [];
      foreach ($images as $index => $image) { $groups[$image->asset][$index] = $image; }
      $results = [];
      foreach ($groups as $group) {
        $decoded = null;
        try {
          foreach ($group as $index => $image) {
            try {
              [$size, $facts] = self::readInSnapshot($root, $image, $decoded);
              $results[$index] = self::projectVisibleBounds($image, $size, $facts);
            } catch (RuntimeException $error) {
              $results[$index] = ['bounds' => null, 'diagnostic' => $error->getMessage()];
            }
          }
        } finally { unset($decoded); }
      }
      ksort($results);
      return array_values($results);
    });
    $cache = self::getFactsCache();
    return $cache === null ? $scan() : $cache->runBatch($scan);
  }

  /** @return array{bounds: ?CanvasRectangle, diagnostic: ?string} */
  private static function projectVisibleBounds(CanvasImage $image, array $size, array $facts): array
  {
    $pixels = $facts['pixels'];
    if ($pixels === null || $image->opacity === 0.0) { return ['bounds' => null, 'diagnostic' => null]; }
    [$x, $y, $right, $bottom] = $image->projectSourceBounds($size['width'], $size['height'], ...$pixels);
    if ($image->clipRect !== null) {
      $x = max($x, $image->clipRect->x);
      $y = max($y, $image->clipRect->y);
      $right = min($right, $image->clipRect->x + $image->clipRect->width);
      $bottom = min($bottom, $image->clipRect->y + $image->clipRect->height);
    }
    return ['bounds' => $right <= $x || $bottom <= $y ? null : new CanvasRectangle(
      $x, $y, $right - $x, $bottom - $y, allowOutsideCanvas: true), 'diagnostic' => null];
  }

  /** @return array{float, float}|null Horizontal span of the solid lower silhouette at a surface height. */
  public static function getSurfaceSpan(string $root, CanvasImage $image, float $surfaceY): ?array
  {
    try {
      [$size, $facts] = self::read($root, $image);
    } catch (RuntimeException) {
      return null;
    }
    if ($image->flipY) { return null; }
    $bounds = $image->destination;
    $row = ($surfaceY - $bounds->y) / $bounds->height * $size['height'];
    $columns = array_keys(array_filter($facts['edge'], static fn(int $edge): bool => $edge + 1 >= $row));
    if ($columns === []) { return null; }
    $left = min($columns);
    $right = max($columns) + 1;
    if ($image->flipX) { [$left, $right] = [$size['width'] - $right, $size['width'] - $left]; }
    return [$bounds->x + $left * $bounds->width / $size['width'],
      $bounds->x + $right * $bounds->width / $size['width']];
  }

  /**
   * Where the art meets the ground in its frame: each column's lowest solid pixel (its lower edge) that lies on the
   * ground. In a three-quarter view the ground recedes up the screen, so a contact further back stands higher: every
   * column whose lower edge lies within a depth of the lowest one touches the ground, the depth being the ground's
   * perspective (a footprint's height on screen over its width) times the art's solid width. So both feet of a wide
   * or staggered stance, every paw of a long body, its belly and a tail lying low, a robe's hem and a lying body all
   * count, while a tail, cloak or weapon held up above the ground does not. The touching columns come back as patches
   * of the frame, split where the art leaves a gap and cut into short pieces that each keep their own lowest point,
   * so a shadow follows the contact's depth along its length.
   *
   * @param float $perspective How flat the ground is drawn: a footprint's height on screen over its width.
   * @return array{top: float, bottom: float, patches: list<array{0: float, 1: float, 2: float}>}|null The solid art's
   *   top and bottom and each patch's left, right and lowest point, as shares of the frame before mirroring; null when
   *   the art cannot be read or has nothing solid.
   */
  public static function getGroundContact(string $root, CanvasImage $image, float $perspective): ?array
  {
    try {
      [$size, $facts] = self::read($root, $image);
    } catch (RuntimeException) {
      return null;
    }
    $edge = $facts['edge'];
    if ($edge === []) { return null; }
    $lowest = max($edge);
    $top = $facts['pixels'][1];
    $art = max(1, $lowest - $top + 1);
    $depth = max(0.0, $perspective) * (max(array_keys($edge)) - min(array_keys($edge)) + 1);
    $gap = max(1, (int) round(self::JOIN_GAP * $art));
    $piece = max(2, (int) round(self::PIECE * $art));
    $stretches = [];
    $current = [];
    $last = PHP_INT_MIN;
    foreach ($edge as $column => $row) {
      if ($lowest - $row > $depth) { continue; }
      if ($current !== [] && $column - $last > $gap + 1) { $stretches[] = $current; $current = []; }
      $current[] = $column;
      $last = $column;
    }
    if ($current !== []) { $stretches[] = $current; }
    $patches = [];
    foreach ($stretches as $stretch) {
      $pieces = array_chunk($stretch, $piece);
      // A last piece shorter than half the rest joins the one before it.
      if (count($pieces) > 1 && count($pieces[count($pieces) - 1]) < $piece / 2) {
        $pieces[count($pieces) - 2] = [...$pieces[count($pieces) - 2], ...array_pop($pieces)];
      }
      foreach ($pieces as $columns) {
        $low = max(array_map(static fn(int $column): int => $edge[$column], $columns));
        $patches[] = [min($columns) / $size['width'], (max($columns) + 1) / $size['width'], ($low + 1) / $size['height']];
      }
    }
    return ['top' => $top / $size['height'], 'bottom' => ($lowest + 1) / $size['height'], 'patches' => $patches];
  }

  /** Single-image queries use the same inspection scope as a crop batch. */
  private static function read(string $root, CanvasImage $image): array
  {
    return PngAssetPreflight::runWithSnapshot(static fn(): array => self::readInSnapshot($root, $image));
  }

  /**
   * The current file's facts for a crop, read once per content revision.
   * @return array{0: array{width: int, height: int}, 1: array{version: string, pixels: ?array, edge: array<int, int>}}|null
   */
  private static function readInSnapshot(string $root, CanvasImage $image, ?GdImage &$decoded = null,
    bool $allowGeneration = true, bool $allowStoreRead = true): ?array
  {
    $size = PngAssetPreflight::inspect($root, $image->asset, $image->sourceRect);
    ['path' => $path, 'revision' => $version] = PngAssetPreflight::getContentIdentity($root, $image->asset);
    $crop = $image->sourceRect;
    $key = $path . ':' . serialize($crop);
    if (!isset(self::$cache[$key]) || self::$cache[$key]['version'] !== $version) {
      $store = $allowStoreRead ? self::getFactsCache() : null;
      $stored = $store?->find($path, $version, $crop, $size['width'], $size['height']);
      if ($stored === null && !$allowGeneration) { return null; }
      if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$cache); }
      if ($stored !== null) {
        self::$cache[$key] = $stored;
        return [$size, $stored];
      }
      if (!function_exists('imagecreatefrompng') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Visible-pixel inspection unavailable: optional GD PNG support is not installed.');
      }
      if ($decoded === null) {
        $span = LatencyTrace::beginSpan();
        try {
          $current = @imagecreatefromstring(PngAssetPreflight::readRevisionBytes($root, $image->asset, $version));
          if ($current === false) { throw new RuntimeException('Visible-pixel inspection could not decode the current PNG.'); }
          // Palette transparency becomes alpha once for the complete source, before any crop is inspected.
          if (!imageistruecolor($current)) { imagepalettetotruecolor($current); }
          $decoded = $current;
        } finally { LatencyTrace::endSpan('png.bounds.decode', $span, ['asset' => $image->asset]); }
      }
      $x = $crop?->x ?? 0;
      $y = $crop?->y ?? 0;
      $width = $size['width'];
      $height = $size['height'];
      if ($x + $width > imagesx($decoded) || $y + $height > imagesy($decoded)) {
        throw new RuntimeException('Visible-pixel crop exceeds the decoded PNG.');
      }
      $span = LatencyTrace::beginSpan();
      try {
        $pixels = self::findVisiblePixels($decoded, $x, $y, $width, $height);
        $edge = $pixels === null ? [] : self::findLowerEdge($decoded, $x, $y, $pixels);
      } finally {
        LatencyTrace::endSpan('png.bounds.scan', $span, ['asset' => $image->asset,
          'source' => [$x, $y, $width, $height]]);
      }
      self::$cache[$key] = ['version' => $version, 'pixels' => $pixels, 'edge' => $edge];
      $store?->store($path, $version, $crop, $width, $height, self::$cache[$key]);
    }
    return [$size, self::$cache[$key]];
  }

  /**
   * Each column's lowest solid pixel, searched up from the lowest solid row through the art's lower part.
   * @param array{int, int, int, int} $pixels The visible extent within the crop.
   * @return array<int, int> Column => row, within the crop; columns with nothing solid there are absent.
   */
  private static function findLowerEdge(\GdImage $image, int $x, int $y, array $pixels): array
  {
    [$left, $top, $width, $height] = $pixels;
    $solid = (127 - self::SOLID + 1) << 24;
    $low = null;
    for ($row = $top + $height - 1; $row >= $top && $low === null; $row--) {
      for ($column = $left; $column < $left + $width; $column++) {
        if (imagecolorat($image, $x + $column, $y + $row) < $solid) { $low = $row; break; }
      }
    }
    if ($low === null) { return []; }
    $floor = (int) max($top, $low - self::EDGE_SEARCH * ($low - $top + 1));
    $edge = [];
    for ($column = $left; $column < $left + $width; $column++) {
      for ($row = $low; $row >= $floor; $row--) {
        if (imagecolorat($image, $x + $column, $y + $row) < $solid) { $edge[$column] = $row; break; }
      }
    }
    return $edge;
  }

  /**
   * Scans inward from each edge and stops at the first visible pixel, so padded artwork reads only its margins.
   * @return array{int, int, int, int}|null Left, top, width and height within the crop; null when fully transparent.
   */
  private static function findVisiblePixels(\GdImage $image, int $x, int $y, int $width, int $height): ?array
  {
    $rightLimit = $x + $width - 1;
    $bottomLimit = $y + $height - 1;
    for ($top = $y; $top <= $bottomLimit; $top++) {
      for ($column = $x; $column <= $rightLimit; $column++) {
        if (imagecolorat($image, $column, $top) < 0x7F000000) { break 2; }
      }
    }
    if ($top > $bottomLimit) { return null; }
    $topHit = $column;
    for ($bottom = $bottomLimit; $bottom > $top; $bottom--) {
      for ($column = $x; $column <= $rightLimit; $column++) {
        if (imagecolorat($image, $column, $bottom) < 0x7F000000) { break 2; }
      }
    }
    $bottomHit = $bottom === $top ? $topHit : $column;
    // The first visible pixels on the top and bottom rows bound both searches.
    for ($left = $x, $leftLimit = min($topHit, $bottomHit); $left < $leftLimit; $left++) {
      for ($row = $top; $row <= $bottom; $row++) {
        if (imagecolorat($image, $left, $row) < 0x7F000000) { break 2; }
      }
    }
    for ($right = $rightLimit, $rightFloor = max($topHit, $bottomHit); $right > $rightFloor; $right--) {
      for ($row = $top; $row <= $bottom; $row++) {
        if (imagecolorat($image, $right, $row) < 0x7F000000) { break 2; }
      }
    }
    return [$left - $x, $top - $y, $right - $left + 1, $bottom - $top + 1];
  }
}
