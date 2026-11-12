<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeOperation, CanvasRectangle};
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

final class GraphicalBattleStrip
{
  public static function compose(string $id, string $asset, SpriteSourceRect $crop, BattleEffectContactRail $rail,
    BattleCanvasLayout $arena, array $track, float $offsetX, float $offsetY): ?CanvasComposite
  {
    $source = $track['contactRail'];
    $flipX = $track['flipX'] ?? false;
    $flipY = $track['flipY'] ?? false;
    if ($flipX) { [$source['left'], $source['right']] = [1 - $source['right'], 1 - $source['left']]; }
    if ($flipY) { $source['ridgeY'] = 1 - $source['ridgeY']; }
    $strip = $rail->createStrip($source);
    [$left, $top, $right, $bottom] = $strip->getBounds();
    $left = max(0, (int)floor($left + $offsetX));
    $top = max(0, (int)floor($top + $offsetY));
    $right = min($arena->width, (int)ceil($right + $offsetX));
    $bottom = min($arena->height, (int)ceil($bottom + $offsetY));
    if ($left >= $right || $top >= $bottom) { return null; }
    $geometry = $strip->toArray();
    $geometry['origin'] = [$strip->origin[0] + $offsetX - $left, $strip->origin[1] + $offsetY - $top];
    $operation = new CanvasCompositeOperation(['type' => 'texture_strip', 'asset' => $asset, 'sourceRect' => $crop->toArray(),
      'geometry' => $geometry, 'flipX' => $flipX, 'flipY' => $flipY]);
    return new CanvasComposite($id, $right - $left, $bottom - $top,
      new CanvasRectangle($left, $top, $right - $left, $bottom - $top), [$operation],
      $track['depth'] === 'behind' ? 90 : 180);
  }
}
