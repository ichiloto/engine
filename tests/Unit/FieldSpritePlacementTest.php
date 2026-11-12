<?php

use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Sprites\FieldSpritePlacement;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;

it('centres whole-cell field art exactly on any field pixel within the protocol pivot and lift ranges', function (int $columns, int $rows) {
    $image = new GraphicalSpriteDefinition('Graphics/effect.png', $columns * FieldViewport::TILE_SIZE, $rows * FieldViewport::TILE_SIZE, layer: 7);
    foreach ([[0.0, 0.0], [23.9, 24.0], [24.0, 47.9], [136.8, 19.2], [-30.5, -100.25], [1000.4, 517.7]] as [$x, $y]) {
        ['position' => $cell, 'image' => $placed] = FieldSpritePlacement::centre($x, $y, $image);
        $left = ($cell->x + .5) * 48 - $placed->pivot->x * $placed->width;
        $top = ($cell->y + 1) * 48 - $placed->pivot->y * $placed->height - $placed->lift;
        expect($left + $placed->width / 2)->toEqualWithDelta($x, 1e-9)
            ->and($top + $placed->height / 2)->toEqualWithDelta($y, .5)
            ->and($placed->pivot->x)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
            ->and($placed->lift)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($placed->height)
            ->and([$placed->asset, $placed->layer, $placed->width, $placed->height])->toBe(['Graphics/effect.png', 7, $image->width, $image->height]);
    }
})->with([[1, 1], [2, 1], [1, 3], [3, 2]]);
