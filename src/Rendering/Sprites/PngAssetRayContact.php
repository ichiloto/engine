<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use GdImage;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use RuntimeException;

/** Traces the source pixels of the current projected, axis-aligned image. */
final class PngAssetRayContact
{
  private const int MINIMUM_OPACITY = 16;
  private const float EPSILON = 1e-9;

  /**
   * For each heading, locate painted contact after its previous one. An interior contact
   * uses the midpoint of the longest continuous painted span.
   * The shared budget counts source pixels inspected across the entire shot.
   *
   * @param array<int, array{direction: Vector2, distance: float}> $rays
   * @return array<int, array{direction: Vector2, distance: float, depth: float}>
   */
  public static function trace(string $root, CanvasImage $image, CanvasRectangle $visibleBounds,
    Vector2 $origin, array $rays, int &$remainingReads, bool $preferInterior = false): array
  {
    if (($image->rotation ?? 0) !== 0) {
      throw new RuntimeException('Battle flight cannot trace rotated target artwork.');
    }
    $ranges = [];
    foreach ($rays as $heading => $ray) {
      $range = self::getRange($visibleBounds, $origin, $ray['direction'], $ray['distance']);
      if ($range !== null) { $ranges[$heading] = $range; }
    }
    if ($ranges === []) { return []; }

    $crop = $image->sourceRect;
    $size = PngAssetPreflight::inspect($root, $image->asset, $crop);
    $source = @imagecreatefrompng($root . DIRECTORY_SEPARATOR . $image->asset);
    if ($source === false || ($crop?->x ?? 0) + $size['width'] > imagesx($source)
      || ($crop?->y ?? 0) + $size['height'] > imagesy($source)) {
      throw new RuntimeException('Battle flight could not read its target artwork.');
    }
    if (!imageistruecolor($source)) { imagepalettetotruecolor($source); }

    $destination = $image->destination;
    $xScale = $size['width'] / $destination->width * ($image->flipX ? -1 : 1);
    $yScale = $size['height'] / $destination->height * ($image->flipY ? -1 : 1);
    $sourceOrigin = new Vector2(
      ($crop?->x ?? 0) + ($image->flipX ? $size['width'] : 0) + ($origin->x - $destination->x) * $xScale,
      ($crop?->y ?? 0) + ($image->flipY ? $size['height'] : 0) + ($origin->y - $destination->y) * $yScale,
    );
    $contacts = [];
    foreach ($ranges as $heading => [$near, $far]) {
      $direction = $rays[$heading]['direction'];
      $contact = self::findPaint($source, $sourceOrigin, new Vector2($direction->x * $xScale, $direction->y * $yScale),
        $near, $far, $image->opacity, $remainingReads, $preferInterior);
      if ($contact !== null) { $contacts[$heading] = ['direction' => $direction, ...$contact]; }
    }
    return $contacts;
  }

  /** @return array{float, float}|null */
  private static function getRange(CanvasRectangle $box, Vector2 $origin, Vector2 $direction, float $after): ?array
  {
    $near = $after;
    $far = INF;
    foreach ([[$box->x, $box->width, $origin->x, $direction->x],
      [$box->y, $box->height, $origin->y, $direction->y]] as [$edge, $extent, $start, $velocity]) {
      if (abs($velocity) < self::EPSILON) {
        if ($start < $edge || $start >= $edge + $extent) { return null; }
        continue;
      }
      $first = ($edge - $start) / $velocity;
      $last = ($edge + $extent - $start) / $velocity;
      $near = max($near, min($first, $last));
      $far = min($far, max($first, $last));
    }
    return $far - $near > self::EPSILON ? [$near, $far] : null;
  }

  /** @return array{distance: float, depth: float}|null */
  private static function findPaint(GdImage $source, Vector2 $origin, Vector2 $direction,
    float $near, float $far, float $opacity, int &$remainingReads, bool $preferInterior): ?array
  {
    [$column, $columnStep, $nextColumn, $columnInterval] = self::startAxis($origin->x, $direction->x, $near);
    [$row, $rowStep, $nextRow, $rowInterval] = self::startAxis($origin->y, $direction->y, $near);
    $paintStart = null;
    $best = null;
    for ($distance = $near; $distance < $far - self::EPSILON; $distance = $next) {
      if ($remainingReads-- <= 0) { throw new RuntimeException('Battle flight exhausted its silhouette tracing budget.'); }
      $next = min($nextColumn, $nextRow, $far);
      // Zero-length corner touches are never visible contact.
      if ($next > $distance + self::EPSILON) {
        $pixel = imagecolorat($source, $column, $row);
        if ($pixel !== false && (127 - (($pixel >> 24) & 127)) * $opacity >= self::MINIMUM_OPACITY) {
          $paintStart ??= $distance;
          if (!$preferInterior && $next - $paintStart >= 2.0) {
            return ['distance' => $paintStart + 1.0, 'depth' => 1.0];
          }
        } elseif ($paintStart !== null) {
          $depth = ($distance - $paintStart) / 2;
          $contact = ['distance' => $paintStart + $depth, 'depth' => $depth];
          if (!$preferInterior) { return $contact; }
          if ($best === null || $depth > $best['depth']) { $best = $contact; }
          $paintStart = null;
        }
      }
      if ($nextColumn <= $next + self::EPSILON) { $column += $columnStep; $nextColumn += $columnInterval; }
      if ($nextRow <= $next + self::EPSILON) { $row += $rowStep; $nextRow += $rowInterval; }
    }
    if ($paintStart !== null) {
      $depth = ($far - $paintStart) / 2;
      if ($best === null || $depth > $best['depth']) {
        $best = ['distance' => $paintStart + $depth, 'depth' => $depth];
      }
    }
    return $best;
  }

  /** @return array{int, int, float, float} */
  private static function startAxis(float $origin, float $velocity, float $distance): array
  {
    $point = $origin + $velocity * $distance;
    if (abs($velocity) < self::EPSILON) { return [(int)floor($point), 0, INF, INF]; }
    $step = $velocity > 0 ? 1 : -1;
    $index = $step > 0 ? (int)floor($point + self::EPSILON) : (int)ceil($point - self::EPSILON) - 1;
    $edge = $step > 0 ? $index + 1 : $index;
    return [$index, $step, ($edge - $origin) / $velocity, abs(1 / $velocity)];
  }
}
