<?php

namespace Ichiloto\Engine\Rendering\Tiles;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Util\Debug;

final class GraphicalTileCollector
{
  private bool $reportedCapacityFailure = false;

  /** @param array<string, GraphicalTileDefinition> $definitions @return list<PresentationTileBatch> */
  public function collectLayers(MapLayerSet $layers, array $definitions, Camera $camera): array
  {
    if ($layers->legacy) { return $this->collect($definitions['terrain'] ?? null, $camera); }
    $batches = [];
    $totalCells = 0;
    $base = $layers->getGameplayLayerAt(-1, -1);
    foreach ($layers->layers as $layer) {
      $definition = $definitions[$layer->name] ?? null;
      if ($definition === null) { continue; }
      $cells = [];
      $metrics = [];
      foreach ($camera->visibleMapRows() as $y => $composed) {
        $displayColumn = 0;
        foreach ($composed as $column => $visibleSymbol) {
          $width = NormalizedRow::symbolWidth($visibleSymbol);
          $x = (int)$camera->position->x + $column;
          $cell = $layer->grid[$y][$x];
          $metrics[$cell] ??= [TerminalText::stripAnsi($cell), NormalizedRow::symbolWidth($cell)];
          [$glyph, $cellWidth] = $metrics[$cell];
          $source = $definition->symbols[$glyph] ?? null;
          if ($source !== null && $displayColumn === $column && $width === 1 && $cellWidth === 1
            && ($layer === $base || $glyph !== ' ')) {
            $screen = $camera->getScreenSpacePosition(new Vector2($x, $y));
            if ($screen->x >= 0 && $screen->y >= 0
              && $screen->x < $camera->screen->getWidth() && $screen->y < $camera->screen->getHeight()) {
              $cells[] = ['column' => (int)$screen->x, 'row' => (int)$screen->y, 'source' => $source];
            }
          }
          $displayColumn += $width;
        }
      }
      $totalCells += count($cells);
      if ($totalCells > PresentationTileBatch::MAX_CELLS) {
        if (!$this->reportedCapacityFailure) {
          Debug::warn('Map tile layers exceed the visible crop budget; keeping the complete terminal map presentation.');
          $this->reportedCapacityFailure = true;
        }
        return [];
      }
      if ($cells !== []) {
        $batches[] = new PresentationTileBatch(PresentationLayerPolicy::getMapLayerId($layer), $definition->asset,
          PresentationLayerPolicy::getMapLayerOrder($layer), $definition->sources, $cells);
      }
    }
    $this->reportedCapacityFailure = false;
    return $batches;
  }

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
