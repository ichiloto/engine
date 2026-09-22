<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** Finite atlas playback over current image dimensions, with optional timed travel. */
final class TitleSpritePresentation
{
  public static function validate(string $root, string $id, mixed $sprite): void
  {
    CanvasValidation::id($id);
    if (!is_array($sprite) || array_diff(array_keys($sprite),
      ['asset', 'grid', 'frames', 'frameSeconds', 'destination', 'travel', 'period', 'visibleSeconds', 'reducedVisible',
        'phaseSeconds', 'frameOffset', 'opacity', 'fadeFraction']) !== []) {
      throw new InvalidArgumentException('Invalid title sprite descriptor.');
    }
    $size = PngAssetPreflight::inspect($root, $sprite['asset'] ?? '');
    [$columns, $rows] = TitlePresentationCatalog::getNumbers($sprite['grid'] ?? [1, 1], 2);
    $frames = $sprite['frames'] ?? 1;
    $duration = $sprite['frameSeconds'] ?? 1;
    if (!is_int($columns) || !is_int($rows) || min($columns, $rows) < 1 || max($columns, $rows) > 128
      || !is_int($frames) || $frames < 1 || $frames > $columns * $rows
      || (!is_int($duration) && !is_float($duration)) || !is_finite((float)$duration) || $duration <= 0
      || $size['width'] < $columns || $size['height'] < $rows) {
      throw new InvalidArgumentException('Title sprite needs a valid atlas grid and frame duration.');
    }
    new CanvasRectangle(...TitlePresentationCatalog::getNumbers($sprite['destination'] ?? null, 4));
    if (!is_bool($sprite['reducedVisible'] ?? true)) { throw new InvalidArgumentException('Invalid reduced-motion visibility.'); }
    [$phase, $offset, $opacity, $fade] = TitlePresentationCatalog::getNumbers(
      [$sprite['phaseSeconds'] ?? 0, $sprite['frameOffset'] ?? 0, $sprite['opacity'] ?? 1, $sprite['fadeFraction'] ?? 0], 4);
    if ($phase < 0 || !is_int($offset) || $offset < 0 || $offset >= $frames || $opacity < 0 || $opacity > 1
      || $fade < 0 || $fade > 0.5) { throw new InvalidArgumentException('Invalid title sprite phase or opacity.'); }
    if (isset($sprite['travel'])) {
      TitlePresentationCatalog::getNumbers($sprite['travel'], 2);
      [$period, $visible] = TitlePresentationCatalog::getNumbers([$sprite['period'] ?? 0, $sprite['visibleSeconds'] ?? 0], 2);
      if ($visible <= 0 || $period < $visible) { throw new InvalidArgumentException('Invalid title sprite passage timing.'); }
    }
  }

  public static function getImage(string $root, string $id, array $sprite, float $elapsed, float $opacity,
    bool $reduced): ?CanvasImage
  {
    if ($opacity <= 0 || ($reduced && !($sprite['reducedVisible'] ?? true))) { return null; }
    $size = PngAssetPreflight::inspect($root, $sprite['asset']);
    [$columns, $rows] = $sprite['grid'] ?? [1, 1];
    $frame = $reduced ? 0 : ((int)floor($elapsed / ($sprite['frameSeconds'] ?? 1))
      + ($sprite['frameOffset'] ?? 0)) % ($sprite['frames'] ?? 1);
    $opacity *= $sprite['opacity'] ?? 1;
    // Reconcile atlas cells from current artwork, not historical dimensions.
    $left = (int)floor(($frame % $columns) * $size['width'] / $columns);
    $top = (int)floor(intdiv($frame, $columns) * $size['height'] / $rows);
    $sw = (int)floor(($frame % $columns + 1) * $size['width'] / $columns) - $left;
    $sh = (int)floor((intdiv($frame, $columns) + 1) * $size['height'] / $rows) - $top;
    [$x, $y, $w, $h] = $sprite['destination'];
    if (!$reduced && isset($sprite['travel'])) {
      $phase = fmod($elapsed + ($sprite['phaseSeconds'] ?? 0), $sprite['period']);
      if ($phase >= $sprite['visibleSeconds']) { return null; }
      $progress = $phase / $sprite['visibleSeconds'];
      $fade = $sprite['fadeFraction'] ?? 0;
      if ($fade > 0) { $opacity *= min(1, $progress / $fade, (1 - $progress) / $fade); }
      $x += $sprite['travel'][0] * $phase / $sprite['visibleSeconds'];
      $y += $sprite['travel'][1] * $phase / $sprite['visibleSeconds'];
    }
    $x1 = max(0, $x); $y1 = max(0, $y); $x2 = min(1350, $x + $w); $y2 = min(720, $y + $h);
    if ($x2 <= $x1 || $y2 <= $y1) { return null; }
    $sx1 = (int)floor(($x1 - $x) / $w * $sw); $sy1 = (int)floor(($y1 - $y) / $h * $sh);
    $sx2 = min($sw, (int)ceil(($x2 - $x) / $w * $sw)); $sy2 = min($sh, (int)ceil(($y2 - $y) / $h * $sh));
    return new CanvasImage($id, $sprite['asset'], new CanvasRectangle($x1, $y1, $x2 - $x1, $y2 - $y1), 1,
      new SpriteSourceRect($left + $sx1, $top + $sy1, $sx2 - $sx1, $sy2 - $sy1), $opacity);
  }
}
