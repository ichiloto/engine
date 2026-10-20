<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;

/**
 * Field sprites are placed by a cell, an image pivot at that cell's ground
 * point and a lift. This finds the three that centre an image on any field
 * pixel. Field art is sized in whole cells, so the result is exact: a cell
 * centre always lies inside the image's width, and the lift needed is less
 * than one cell, never more than the image's height.
 */
final class FieldSpritePlacement
{
    /**
     * The image, keeping its art, layer and frame, centred on a field pixel
     * measured from the map origin, and the cell that places it.
     *
     * @return array{position: Vector2, image: GraphicalSpriteDefinition}
     */
    public static function centre(float $x, float $y, GraphicalSpriteDefinition $image): array
    {
        $cell = FieldViewport::TILE_SIZE;
        $column = (int)round($x / $cell - .5);
        $bottom = $y + $image->height / 2;
        $row = (int)ceil($bottom / $cell) - 1;
        $pivot = new PresentationSpritePivot(max(0.0, min(1.0, .5 + (($column + .5) * $cell - $x) / $image->width)), 1.0);
        $lift = min($image->height, (int)round(($row + 1) * $cell - $bottom));

        return ['position' => new Vector2($column, $row), 'image' => new GraphicalSpriteDefinition($image->asset,
            $image->width, $image->height, $image->anchor, $image->layer, $image->sourceRect, $lift, $image->quarterTurns, $pivot)];
    }
}
