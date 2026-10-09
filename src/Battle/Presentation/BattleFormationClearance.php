<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use RuntimeException;

/** Reports authored resting-formation clearance; never moves or rescales battlers. */
final class BattleFormationClearance
{
  /**
   * @param list<CanvasImage> $party
   * @param list<CanvasImage> $enemies
   * @param array<string, string> $battlerLabels Image identity to author-facing battler label.
   * @return array<string, list<string>> Image identity to diagnostics.
   */
  public static function inspect(BattleCanvasLayout $layout, array $party, array $enemies, string $assetRoot,
    array $battlerLabels = []): array
  {
    $images = [...$party, ...$enemies];
    $enemyIds = array_column($enemies, 'id');
    $issues = $visible = [];
    foreach ($images as $image) {
      $issues[$image->id] = [];
      $result = PngAssetBounds::getVisibleBounds($assetRoot, $image);
      if ($result['diagnostic'] !== null) { $issues[$image->id][] = $result['diagnostic']; continue; }
      if ($result['bounds'] === null) { $issues[$image->id][] = 'Battler artwork has no visible pixels.'; continue; }
      $visible[$image->id] = $result['bounds'];
      if ($layout->battlerArea !== null && !self::contains($layout->battlerArea, $result['bounds'])) {
        $issues[$image->id][] = 'Visible battler artwork extends outside the authored battler area.';
      }
      $side = in_array($image->id, $enemyIds, true) ? 'enemy' : 'party';
      $area = $side === 'enemy' ? $layout->enemyArea : $layout->partyArea;
      if ($area !== null && !self::contains($area, $result['bounds'])) {
        $issues[$image->id][] = "Visible {$side} artwork extends outside the authored {$side} area.";
      }
    }
    foreach ($images as $index => $image) {
      $bounds = $visible[$image->id] ?? null;
      if ($bounds === null) { continue; }
      foreach (array_slice($images, $index + 1) as $other) {
        $otherBounds = $visible[$other->id] ?? null;
        if ($otherBounds !== null && self::overlaps($bounds, $otherBounds)) {
          $issues[$image->id][] = 'Visible artwork overlaps battler ' . ($battlerLabels[$other->id] ?? $other->id) . '.';
          $issues[$other->id][] = 'Visible artwork overlaps battler ' . ($battlerLabels[$image->id] ?? $image->id) . '.';
        }
      }
      $cursor = $layout->skin?->targetCursor;
      if ($cursor === null) { continue; }
      $area = in_array($image->id, $enemyIds, true) ? $layout->enemyArea : $layout->partyArea;
      $occupied = [...self::getOutsideAreas($layout, $layout->battlerArea), ...self::getOutsideAreas($layout, $area)];
      foreach ($visible as $id => $otherBounds) { if ($id !== $image->id) { $occupied[] = $otherBounds; } }
      try {
        // Runtime cursors attach to the full posed rectangle, not an inferred body.
        $placed = $cursor->layout($image->destination, $layout->width, $layout->height, occupied: $occupied);
        if ($placed === null) { $issues[$image->id][] = 'No target cursor placement clears the authored region and other battlers.'; }
      } catch (RuntimeException $error) { $issues[$image->id][] = $error->getMessage(); }
    }
    return $issues;
  }

  /** The caller owns identity and slot order; image keys remain opaque. */
  public static function getBattlerLabel(string $name, bool $party, int $index): string
  {
    return sprintf('%s (%s %d)', $name, $party ? 'party slot' : 'enemy member', $index + 1);
  }

  private static function contains(CanvasRectangle $area, CanvasRectangle $bounds): bool
  {
    $epsilon = .000001;
    return $bounds->x >= $area->x - $epsilon && $bounds->y >= $area->y - $epsilon
      && $bounds->x + $bounds->width <= $area->x + $area->width + $epsilon
      && $bounds->y + $bounds->height <= $area->y + $area->height + $epsilon;
  }

  private static function overlaps(CanvasRectangle $one, CanvasRectangle $two): bool
  {
    return $one->x < $two->x + $two->width && $one->x + $one->width > $two->x
      && $one->y < $two->y + $two->height && $one->y + $one->height > $two->y;
  }

  /** @return list<CanvasRectangle> */
  private static function getOutsideAreas(BattleCanvasLayout $layout, ?CanvasRectangle $area): array
  {
    if ($area === null) { return []; }
    $outside = [];
    if ($area->x > 0) { $outside[] = new CanvasRectangle(0, 0, $area->x, $layout->height); }
    if ($area->y > 0) { $outside[] = new CanvasRectangle(0, 0, $layout->width, $area->y); }
    $right = $area->x + $area->width;
    $bottom = $area->y + $area->height;
    if ($right < $layout->width) { $outside[] = new CanvasRectangle($right, 0, $layout->width - $right, $layout->height); }
    if ($bottom < $layout->height) { $outside[] = new CanvasRectangle(0, $bottom, $layout->width, $layout->height - $bottom); }
    return $outside;
  }
}
