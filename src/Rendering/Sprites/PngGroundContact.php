<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * Where a sprite's opaque art meets the ground, read from the current image.
 * The art's lower edge is each column's lowest opaque pixel; a plank laid
 * under it (the edge's lower convex hull) touches what bears the weight:
 * both feet of a stance whatever their depth on screen, every paw, a lying
 * body or a coiled snake along its length, while hems, cloak tips and tails
 * that hang above the plank do not. Of the touching stretches near the
 * lowest point (searched within the lowest part of the art), slivers much narrower than the widest (a cloak tip, a tail
 * tip resting on the plank's end) are left out. Each stretch is returned as
 * patches of the frame: left, right and the height its lowest point stands
 * at, as shares of the frame, so they follow the art wherever and however
 * large it is drawn. Long stretches come back in pieces so a contact can
 * follow their slope. A body lying down meets the ground along its whole
 * lower edge, nearer and further on screen alike, so the whole edge comes
 * back too, in the same pieces (`edge`). The art's own top and bottom come
 * with them, so a consumer can size by the art rather than its padding.
 *
 * Facts are read from the file as it is now and cached per file revision and
 * crop; replacing the image needs no metadata. Reading needs GD: without it,
 * or for an unreadable image, there is no answer and the caller estimates.
 */
final class PngGroundContact
{
  /** Opacity, out of 127, at which a pixel counts as art rather than haze or a painted shadow's edge. */
  private const int OPAQUE = 64;
  /** How far above the lowest point a stretch may touch the plank, as a share of the art's height. */
  private const float CONTACT_DEPTH = .2;
  /** How close to the plank a column's lowest pixel must be to touch it, as a share of the art's height. */
  private const float TOUCH = .02;
  /** A stretch narrower than this share of the widest one is a tip resting on the plank, not a contact. */
  private const float LEAST_SHARE = .3;
  /** How much of the art, up from its lowest point, is searched for its lower edge. */
  private const float SEARCH = .6;
  /** Gaps narrower than this, as a share of the art's height, do not split a stretch. */
  private const float JOIN_GAP = .02;
  /** The longest piece a stretch is returned in, as a share of the art's height. */
  private const float PIECE = .08;
  private const int MAX_CACHE_ENTRIES = 512;
  /** @var array<string, array{version: array, contact: ?array{top: float, bottom: float, patches: list<array{0: float, 1: float, 2: float}>, edge: list<array{0: float, 1: float, 2: float}>}}> */
  private static array $cache = [];
  private static bool $reportedUnavailable = false;

  /**
   * @return array{top: float, bottom: float, patches: list<array{0: float, 1: float, 2: float}>, edge: list<array{0: float, 1: float, 2: float}>}|null
   *   The art's top and bottom, each standing contact's left, right and lowest height, and the same for the whole lower
   *   edge, as shares of the frame; null when the art cannot be read (no GD, an unreadable file) or has nothing opaque.
   */
  public static function getContact(string $root, string $asset, ?SpriteSourceRect $crop = null): ?array
  {
    $path = realpath($root . DIRECTORY_SEPARATOR . $asset);
    $canonicalRoot = realpath($root);
    if ($path === false || $canonicalRoot === false || !str_starts_with($path, $canonicalRoot . DIRECTORY_SEPARATOR)) { return null; }
    clearstatcache(true, $path);
    $stat = @stat($path);
    $version = [$stat['mtime'] ?? null, $stat['ctime'] ?? null, $stat['size'] ?? null, $stat['ino'] ?? null];
    $key = $path . ':' . ($crop === null ? '' : "{$crop->x},{$crop->y},{$crop->width},{$crop->height}");
    if (!isset(self::$cache[$key]) || self::$cache[$key]['version'] !== $version) {
      if (count(self::$cache) >= self::MAX_CACHE_ENTRIES) { array_shift(self::$cache); }
      try {
        // The Engine's own limits on what artwork may be (a PNG, within size) hold before anything is decoded.
        PngAssetPreflight::inspect($root, $asset, $crop);
        $contact = self::measure($path, $crop);
      } catch (RuntimeException $error) {
        Debug::warn('Ground contact unavailable for ' . $asset . ': ' . $error->getMessage());
        $contact = null;
      }
      self::$cache[$key] = ['version' => $version, 'contact' => $contact];
    }
    return self::$cache[$key]['contact'];
  }

  /** @return array{top: float, bottom: float, patches: list<array{0: float, 1: float, 2: float}>, edge: list<array{0: float, 1: float, 2: float}>}|null */
  private static function measure(string $path, ?SpriteSourceRect $crop): ?array
  {
    if (!function_exists('imagecreatefrompng')) {
      if (!self::$reportedUnavailable) {
        Debug::warn('Ground contact reading unavailable: optional GD PNG support is not installed; battle shadows are estimated.');
        self::$reportedUnavailable = true;
      }
      return null;
    }
    $image = @imagecreatefrompng($path);
    if ($image === false) { throw new RuntimeException('the PNG could not be decoded'); }
    [$x0, $y0] = [$crop?->x ?? 0, $crop?->y ?? 0];
    [$width, $height] = [$crop?->width ?? imagesx($image), $crop?->height ?? imagesy($image)];
    if ($x0 + $width > imagesx($image) || $y0 + $height > imagesy($image)) { throw new RuntimeException('the crop exceeds the image'); }
    $opaque = static fn(int $x, int $y): bool => 127 - ((imagecolorat($image, $x0 + $x, $y0 + $y) >> 24) & 127) >= self::OPAQUE;
    // The art's top, read down from the frame's top, and its lowest row, read up from the bottom, scale the contact
    // rules to the art rather than its padding.
    $top = $low = null;
    for ($y = 0; $y < $height && $top === null; $y++) {
      for ($x = 0; $x < $width; $x++) { if ($opaque($x, $y)) { $top = $y; break; } }
    }
    if ($top === null) { return null; }
    for ($y = $height - 1; $y >= $top && $low === null; $y--) {
      for ($x = 0; $x < $width; $x++) { if ($opaque($x, $y)) { $low = $y; break; } }
    }
    // Only the art's lower part can meet the ground: each column's lowest pixel is looked for there.
    $floor = (int) max($top, $low - self::SEARCH * ($low - $top + 1));
    $bottom = [];
    for ($x = 0; $x < $width; $x++) {
      for ($y = $low; $y >= $floor; $y--) { if ($opaque($x, $y)) { $bottom[$x] = $y; break; } }
    }
    $sole = max($bottom);
    $art = max(1, $sole - $top + 1);
    $plank = self::getPlank($bottom);
    $gap = max(1, (int) round(self::JOIN_GAP * $art));
    $stretches = [];
    $current = [];
    $last = PHP_INT_MIN;
    foreach ($bottom as $x => $y) {
      if ($sole - $y > self::CONTACT_DEPTH * $art || $plank($x) - $y > self::TOUCH * $art) { continue; }
      if ($current !== [] && $x - $last > $gap + 1) { $stretches[] = $current; $current = []; }
      $current[] = $x;
      $last = $x;
    }
    if ($current !== []) { $stretches[] = $current; }
    // A flat sole on a sloping plank touches it at a corner: each stretch takes in the rest of its own sole.
    $grown = [];
    foreach ($stretches as $stretch) {
      $lowest = max(array_map(static fn(int $x): int => $bottom[$x], $stretch));
      [$from, $to] = [min($stretch), max($stretch)];
      while (isset($bottom[$from - 1]) && $lowest - $bottom[$from - 1] <= self::TOUCH * $art) { $from--; }
      while (isset($bottom[$to + 1]) && $lowest - $bottom[$to + 1] <= self::TOUCH * $art) { $to++; }
      if ($grown !== [] && $from <= max($grown[count($grown) - 1]) + $gap + 1) {
        $grown[count($grown) - 1] = range(min($grown[count($grown) - 1]), max($to, max($grown[count($grown) - 1])));
      } else {
        $grown[] = range($from, $to);
      }
    }
    $stretches = array_map(static fn(array $stretch): array => array_values(array_filter($stretch,
      static fn(int $x): bool => isset($bottom[$x]))), $grown);
    $widest = max(array_map(static fn(array $stretch): int => max($stretch) - min($stretch) + 1, $stretches));
    $piece = max(2, (int) round(self::PIECE * $art));
    $toPatches = static function (array $stretches) use ($bottom, $piece, $width, $height): array {
      $patches = [];
      foreach ($stretches as $stretch) {
        $pieces = array_chunk($stretch, $piece);
        // A last piece shorter than half the rest joins the one before it.
        if (count($pieces) > 1 && count($pieces[count($pieces) - 1]) < $piece / 2) {
          $pieces[count($pieces) - 2] = [...$pieces[count($pieces) - 2], ...array_pop($pieces)];
        }
        foreach ($pieces as $columns) {
          $lowest = max(array_map(static fn(int $x): int => $bottom[$x], $columns));
          $patches[] = [min($columns) / $width, (max($columns) + 1) / $width, ($lowest + 1) / $height];
        }
      }
      return $patches;
    };
    $contacts = array_values(array_filter($stretches,
      static fn(array $stretch): bool => max($stretch) - min($stretch) + 1 >= self::LEAST_SHARE * $widest));
    // The whole lower edge, its stretches split where the art leaves a gap.
    $edge = [];
    $current = [];
    $last = PHP_INT_MIN;
    foreach (array_keys($bottom) as $x) {
      if ($current !== [] && $x - $last > $gap + 1) { $edge[] = $current; $current = []; }
      $current[] = $x;
      $last = $x;
    }
    if ($current !== []) { $edge[] = $current; }
    return ['top' => $top / $height, 'bottom' => ($sole + 1) / $height, 'patches' => $toPatches($contacts), 'edge' => $toPatches($edge)];
  }

  /**
   * The plank under the art's lower edge: its lower convex hull (screen
   * heights grow downward), as the height it passes at each column.
   *
   * @param array<int, int> $bottom Each column's lowest opaque pixel.
   * @return \Closure(int): float
   */
  private static function getPlank(array $bottom): \Closure
  {
    $hull = [];
    foreach ($bottom as $x => $y) {
      // Convex seen from below: a point above the line between its neighbours leaves the hull.
      while (count($hull) >= 2) {
        [$ax, $ay] = $hull[count($hull) - 2];
        [$bx, $by] = $hull[count($hull) - 1];
        if (($bx - $ax) * ($y - $ay) - ($by - $ay) * ($x - $ax) < 0) { break; }
        array_pop($hull);
      }
      $hull[] = [$x, $y];
    }
    return static function (int $x) use ($hull): float {
      for ($index = 1; $index < count($hull); $index++) {
        if ($x <= $hull[$index][0]) {
          [$x0, $y0] = $hull[$index - 1];
          [$x1, $y1] = $hull[$index];
          return $x1 === $x0 ? (float) $y1 : $y0 + ($y1 - $y0) * ($x - $x0) / ($x1 - $x0);
        }
      }
      return (float) $hull[count($hull) - 1][1];
    };
  }
}
