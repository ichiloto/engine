<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteThicket;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TilesetThicket;

/**
 * The thickets of a map's graphical field: which foliage hides a character
 * standing in a cell ({@see TilesetThicket}). It reads the tiles the cell
 * shows, the same tiles the graphical field draws, so a character is hidden
 * where foliage art is drawn and nowhere else; the cell's terrain kind
 * decides through its own autotile, and any other tile through the thicket
 * that lists it. Of several thickets in one cell the deepest hides, and of
 * equally deep ones the uppermost tile layer's.
 *
 * Presentation only, like the map's graphics it reads: collision, movement,
 * encounters, the terminal and saves never see it.
 */
final readonly class MapThickets
{
  /** @var list<int> The tileset's corner-edged kinds' autotiles, whose art is drawn on the corners between cells. */
  private array $cornerTiles;

  public function __construct(public MapGraphics $graphics)
  {
    $this->cornerTiles = $graphics->tileset->getCornerTiles();
  }

  /**
   * How a character standing in this cell is hidden, or null in a cell no
   * thicket's foliage is drawn in. Under reduced motion the foliage holds
   * still, so it never rustles.
   */
  public function findSpriteThicketAt(int $x, int $y, bool $reducedMotion): ?PresentationSpriteThicket
  {
    if ($this->graphics->tileset->thickets === []) {
      return null;
    }
    $found = null;
    foreach ($this->graphics->layers as $layer) {
      $tile = $layer->getTileAt($x, $y);
      $thicket = $tile === null ? null : $this->graphics->tileset->getThicket($tile);
      if ($thicket !== null && ($found === null || $thicket->depth >= $found[0]->depth)) {
        $found = [$thicket, $layer->name, $tile];
      }
    }
    if ($found === null) {
      return null;
    }
    [$thicket, $layer, $tile] = $found;
    // The rustle parts the foliage art the cell itself shows: its tile on the band its layer draws it in. A
    // corner-edged kind's art is drawn on the corners between cells, so no one tile of a cell shows it.
    $rustles = $thicket->rustle && !$reducedMotion && !CornerShape::isCornerTile($tile, $this->cornerTiles);
    return new PresentationSpriteThicket($thicket->depth, $thicket->opacity,
      $rustles ? PresentationLayerPolicy::getTileLayerId($layer, $this->graphics->tileset->isAbove($tile)) : null);
  }
}
