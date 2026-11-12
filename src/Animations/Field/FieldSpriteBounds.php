<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\ScreenSpaceSpriteProviderInterface;

/**
 * Where a field image is drawn, in world logical pixels at native size: the
 * image pivot at its cell's ground anchor, raised by its lift and, at the
 * top of its rise, its bob, as the renderer places it. Screen-pinned images
 * are carried into the same space through the field viewport, so guidance
 * at the view edge and characters in the field compare directly.
 * Presentation only: collision and events never read it.
 */
final readonly class FieldSpriteBounds
{
  public function __construct(public float $left, public float $top, public float $right, public float $bottom) {}

  /**
   * Where a sprite is drawn now: in the field at its world cell, or, pinned
   * to the screen, at its session-grid cell through the viewport.
   *
   * @param array{x: int, y: int} $origin The world cell at the view's top left.
   */
  public static function findBounds(GraphicalSpriteProviderInterface $sprite, ?FieldViewport $view, array $origin): ?self
  {
    $definition = $sprite->getGraphicalSpriteDefinition();
    if ($definition === null) { return null; }
    $position = $sprite->getGraphicalSpriteWorldPosition();
    if (!$sprite instanceof ScreenSpaceSpriteProviderInterface) {
      return self::place($definition, ($position->x + 0.5) * FieldViewport::TILE_SIZE,
        ($position->y + 1) * FieldViewport::TILE_SIZE);
    }
    if ($view === null) { return null; }
    $grid = $view->grid;
    $tile = FieldViewport::TILE_SIZE * $view->zoom;
    $surface = self::place($definition, ($position->x + 0.5) * $grid->cellWidth, ($position->y + 1) * $grid->cellHeight);
    $offsetX = ($grid->columns * $grid->cellWidth - $view->columns * $tile) / 2 - $origin['x'] * $tile;
    $offsetY = ($grid->rows * $grid->cellHeight - $view->rows * $tile) / 2 - $origin['y'] * $tile;

    return new self(($surface->left - $offsetX) / $view->zoom, ($surface->top - $offsetY) / $view->zoom,
      ($surface->right - $offsetX) / $view->zoom, ($surface->bottom - $offsetY) / $view->zoom);
  }

  /**
   * Where a sprite's image rests now on the session surface, in surface
   * pixels: through the camera and field viewport, as the renderer places
   * the sprite the Game sends for it, or at its session-grid cell when it is
   * pinned to the screen. Its bob, which the renderer animates, is left out.
   */
  public static function findSurfaceBounds(GraphicalSpriteProviderInterface $sprite, Camera $camera, FieldViewport $view): ?self
  {
    $definition = $sprite->getGraphicalSpriteDefinition();
    if ($definition === null) { return null; }
    $grid = $view->grid;
    $position = $sprite->getGraphicalSpriteWorldPosition();
    if ($sprite instanceof ScreenSpaceSpriteProviderInterface) {
      return self::placeAtRest($definition, ($position->x + 0.5) * $grid->cellWidth, ($position->y + 1) * $grid->cellHeight, 1.0);
    }
    $cell = $camera->getScreenSpacePosition($position);
    $tile = FieldViewport::TILE_SIZE * $view->zoom;
    $offsetX = max(0, ($grid->columns * $grid->cellWidth - $view->columns * $tile) / 2);
    $offsetY = max(0, ($grid->rows * $grid->cellHeight - $view->rows * $tile) / 2);

    return self::placeAtRest($definition, $offsetX + ($cell->x + 0.5) * $tile, $offsetY + ($cell->y + 1) * $tile, $view->zoom);
  }

  /** The smallest bounds holding all of these, or null for none. @param list<self|null> $bounds */
  public static function join(array $bounds): ?self
  {
    $bounds = array_values(array_filter($bounds));
    if ($bounds === []) { return null; }

    return new self(min(array_map(static fn(self $b): float => $b->left, $bounds)),
      min(array_map(static fn(self $b): float => $b->top, $bounds)),
      max(array_map(static fn(self $b): float => $b->right, $bounds)),
      max(array_map(static fn(self $b): float => $b->bottom, $bounds)));
  }

  /** Whether the two share drawn area; images that only touch do not. */
  public function overlaps(self $other): bool
  {
    return $this->left < $other->right && $other->left < $this->right
      && $this->top < $other->bottom && $other->top < $this->bottom;
  }

  /** An image placed at a ground anchor, in the pixels its anchor is given in, with the top of its bob. */
  private static function place(GraphicalSpriteDefinition $definition, float $anchorX, float $anchorY): self
  {
    $rest = self::placeAtRest($definition, $anchorX, $anchorY, 1.0);

    return new self($rest->left, $rest->top - ($definition->bob?->height ?? 0), $rest->right, $rest->bottom);
  }

  /** An image at rest on a ground anchor: its pivot on the anchor, raised by its lift, all at this scale. */
  private static function placeAtRest(GraphicalSpriteDefinition $definition, float $anchorX, float $anchorY, float $scale): self
  {
    $pivot = $definition->pivot ?? new PresentationSpritePivot(0.5, 1.0);
    $width = $definition->width * $scale;
    $height = $definition->height * $scale;
    $left = $anchorX - $pivot->x * $width;
    $top = $anchorY - $pivot->y * $height - $definition->lift * $scale;

    return new self($left, $top, $left + $width, $top + $height);
  }
}
