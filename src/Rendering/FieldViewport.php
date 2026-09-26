<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use InvalidArgumentException;

/**
 * The graphical field's camera: one terminal cell is one square field cell of
 * CELL_SIZE logical pixels (RPG Maker's tile size), scaled by the display zoom.
 * The field has its own cell pitch; UI text keeps the session text grid.
 */
final readonly class FieldViewport
{
  /** RPG Maker's tile size: one terminal cell draws as a 48 x 48 logical-pixel square. */
  public const int CELL_SIZE = 48;
  public const float DEFAULT_ZOOM = 1.0;
  public const float MIN_ZOOM = 1.0;
  public const float MAX_ZOOM = PresentationViewport::MAX_SCALE;
  public int $columns;
  public int $rows;

  public function __construct(public RendererGridConfig $grid, public float $zoom = self::DEFAULT_ZOOM)
  {
    if (!is_finite($zoom) || $zoom < self::MIN_ZOOM || $zoom > self::MAX_ZOOM) {
      throw new InvalidArgumentException('graphics.field.zoom must be a finite number from 1 to 8.');
    }
    // As many whole field cells as the session surface holds; the remainder is
    // split evenly around the field.
    $pitch = self::CELL_SIZE * $zoom;
    $this->columns = max(1, (int)floor($grid->columns * $grid->cellWidth / $pitch));
    $this->rows = max(1, (int)floor($grid->rows * $grid->cellHeight / $pitch));
  }

  /** @param list<PresentationTextLayer> $text @param list<PresentationSprite> $sprites @param list<PresentationTileBatch> $tiles */
  public function createViewport(array|ConsolePresentationSnapshot|ConsolePresentationChanges $text, array $sprites, array $tiles = [],
    ?string $worldId = null, array $worldOrigin = ['x' => 0, 'y' => 0]): PresentationViewport
  {
    if ($text instanceof ConsolePresentationSnapshot) { $text = $text->textLayers; }
    elseif ($text instanceof ConsolePresentationChanges) {
      $text = array_map(static fn(array $layer): PresentationTextLayer => new PresentationTextLayer($layer['id'], $layer['layer'], []), $text->layers);
    }
    $width = $this->grid->columns * $this->grid->cellWidth;
    $height = $this->grid->rows * $this->grid->cellHeight;
    return new PresentationViewport($this->zoom,
      max(0, ($width - $this->columns * self::CELL_SIZE * $this->zoom) / 2),
      max(0, ($height - $this->rows * self::CELL_SIZE * $this->zoom) / 2),
      new CanvasRectangle(0, 0, $width, $height),
      array_values(array_map(static fn($layer) => $layer->id,
        array_filter($text, static fn($layer) => $layer->layer < PresentationLayerPolicy::UI
          || $layer->id === PresentationLayerPolicy::FIELD_PROMPT_ID))),
      array_column($sprites, 'id'), array_column($tiles, 'id'), $worldId, $worldOrigin['x'], $worldOrigin['y']);
  }
}
