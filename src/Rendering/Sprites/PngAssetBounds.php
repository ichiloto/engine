<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;

/**
 * What current artwork shows, read from its pixels once per content revision and crop: its visible extent as drawn,
 * which battle overlays attach to and author diagnostics measure, and where it meets the ground, which its shadow
 * lies under. Replacing the image needs no metadata. Read-only and presentation-only; never a native PNG decode or
 * gameplay gate.
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

  /** @return array{bounds: ?CanvasRectangle, diagnostic: ?string} */
  public static function getVisibleBounds(string $root, CanvasImage $image): array
  {
    try {
      [$size, $facts] = self::read($root, $image);
      $pixels = $facts['pixels'];
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

  /**
   * The current file's facts for a crop, read once per content revision.
   * @return array{0: array{width: int, height: int}, 1: array{version: string, pixels: ?array, edge: array<int, int>}}
   */
  private static function read(string $root, CanvasImage $image): array
  {
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
      $edge = $pixels === null ? [] : self::findLowerEdge($decoded, $x, $y, $pixels);
      unset($decoded);
      self::$cache[$key] = ['version' => $version, 'pixels' => $pixels, 'edge' => $edge];
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
