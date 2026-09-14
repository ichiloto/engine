<?php

namespace Ichiloto\Engine\Rendering\Tiles;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;

final class GraphicalTileCollector
{
  /** @return list<PresentationTileBatch> One atlas/layer, bounded by the visible authored region. */
  public function collect(?GraphicalTileDefinition $definition, Camera $camera): array
  {
    if ($definition === null) { return []; }
    $cells = $metrics = [];
    foreach ($camera->visibleMapRows() as $y => $symbols) {
      $displayColumn = 0;
      foreach ($symbols as $column => $symbol) {
        $metrics[$symbol] ??= [TerminalText::stripAnsi($symbol), NormalizedRow::symbolWidth($symbol)];
        [$normalized, $width] = $metrics[$symbol];
        $source = $definition->symbols[$normalized] ?? null;
        // Wide unmapped art retains its existing text path. Never cut a hole
        // where that path no longer coincides with a logical map-cell anchor.
        if ($source !== null && $displayColumn === $column && $width === 1) {
          $screen = $camera->getScreenSpacePosition(new Vector2((int)$camera->position->x + $column, $y));
          if ($screen->x >= 0 && $screen->y >= 0
            && $screen->x < $camera->screen->getWidth() && $screen->y < $camera->screen->getHeight()) {
            $cells[] = ['column' => (int)$screen->x, 'row' => (int)$screen->y, 'source' => $source];
          }
        }
        $displayColumn += $width;
      }
    }
    return $cells === [] ? [] : [new PresentationTileBatch(PresentationLayerPolicy::TERRAIN_ID,
      $definition->asset, PresentationLayerPolicy::TERRAIN, $definition->sources, $cells)];
  }
}
