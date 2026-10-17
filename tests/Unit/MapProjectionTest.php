<?php

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Field\MapProjection;

it('fits every map edge without cropping and keeps display scale and margins consistent',
    function (int $mapWidth, int $mapHeight, Rect $bounds, float $unitWidth, float $unitHeight) {
        $projection = MapProjection::fit($mapWidth, $mapHeight, $bounds, $unitWidth, $unitHeight);
        $first = $projection->projectPoint(0, 0);
        $last = $projection->projectPoint($mapWidth, $mapHeight);
        $left = $first->x - $bounds->getX();
        $right = $bounds->getX() + $bounds->getWidth() - $last->x;
        $top = $first->y - $bounds->getY();
        $bottom = $bounds->getY() + $bounds->getHeight() - $last->y;
        expect($left)->toBeGreaterThanOrEqual(-1e-9)->and($right)->toBeGreaterThanOrEqual(-1e-9)
            ->and($top)->toBeGreaterThanOrEqual(-1e-9)->and($bottom)->toBeGreaterThanOrEqual(-1e-9)
            ->and(abs($left - $right))->toBeLessThan(1e-9)
            ->and(abs($top - $bottom))->toBeLessThan(1e-9)
            ->and(min($left, $top))->toBeLessThan(1e-9)
            ->and(abs($projection->scaleX * $unitWidth - $projection->scaleY * $unitHeight))->toBeLessThan(1e-9);
        $centre = $projection->projectPoint($mapWidth / 2, $mapHeight / 2);
        expect(abs($centre->x - ($bounds->getX() + $bounds->getWidth() / 2)))->toBeLessThan(1e-9)
            ->and(abs($centre->y - ($bounds->getY() + $bounds->getHeight() / 2)))->toBeLessThan(1e-9);
    })->with([
        'wide graphical map' => [400, 30, new Rect(12, 20, 220, 160), 1.0, 1.0],
        'tall graphical map' => [20, 500, new Rect(12, 20, 220, 160), 1.0, 1.0],
        'square Terminal overview' => [100, 100, new Rect(1, 2, 78, 20), 1.0, 2.0],
        'single-cell map' => [1, 1, new Rect(12, 20, 220, 160), 1.0, 1.0],
        'large map and narrow Terminal' => [10000, 9000, new Rect(0, 0, 30, 12), 8.0, 17.0],
    ]);

it('places player and landmark centres identically at both map extremes and across view sizes', function () {
    foreach ([new Rect(4, 6, 220, 160), new Rect(0, 0, 900, 600), new Rect(1, 2, 78, 20)] as $bounds) {
        $projection = MapProjection::fit(200, 80, $bounds);
        foreach ([[0, 0], [100, 40], [199, 79]] as [$x, $y]) {
            $player = $projection->projectCell($x, $y);
            $landmark = $projection->projectPoint($x + 0.5, $y + 0.5);
            expect($player)->toEqual($landmark)
                ->and($player->x)->toBeGreaterThan($projection->left)
                ->and($player->x)->toBeLessThan($projection->left + $projection->width)
                ->and($player->y)->toBeGreaterThan($projection->top)
                ->and($player->y)->toBeLessThan($projection->top + $projection->height);
        }
        expect($projection->projectCell(199, 79)->x)->toBeGreaterThan($projection->projectCell(0, 0)->x)
            ->and($projection->projectCell(199, 79)->y)->toBeGreaterThan($projection->projectCell(0, 0)->y);
    }
});

it('detaches mutable view bounds and never refits the map when the player moves', function () {
    $bounds = new Rect(10, 20, 200, 100);
    $projection = MapProjection::fit(100, 50, $bounds);
    $before = $projection->projectPoint(100, 50);
    $bounds->setX(999)->setWidth(1);
    foreach (range(0, 99) as $x) { $projection->projectCell($x, 24); }
    expect($projection->projectPoint(100, 50))->toEqual($before)
        ->and($projection->left)->toBe(10.0)->and($projection->top)->toBe(20.0);
});

it('rejects invalid extents without inventing another map or scale', function (int $width, int $height, Rect $bounds,
    float $unitWidth = 1, float $unitHeight = 1) {
    expect(fn() => MapProjection::fit($width, $height, $bounds, $unitWidth, $unitHeight))
        ->toThrow(InvalidArgumentException::class);
})->with([
    [0, 2, new Rect(0, 0, 10, 10)], [-1, 2, new Rect(0, 0, 10, 10)],
    [2, 0, new Rect(0, 0, 10, 10)], [2, 2, new Rect(0, 0, 0, 10)],
    [2, 2, new Rect(0, 0, 10, -1)], [2, 2, new Rect(0, 0, 10, 10), 0.0, 1.0],
    [2, 2, new Rect(0, 0, 10, 10), 1.0, INF], [2, 2, new Rect(0, 0, 10, 10), NAN, 1.0],
    [2, 2, new Rect(0, 0, 10, 10), PHP_FLOAT_MAX, 1.0],
]);

it('rejects off-map cells and nonfinite points rather than moving their markers onto an edge', function () {
    $projection = MapProjection::fit(10, 5, new Rect(0, 0, 100, 100));
    foreach ([[-1, 0], [10, 0], [0, 5]] as [$x, $y]) {
        expect(fn() => $projection->projectCell($x, $y))->toThrow(InvalidArgumentException::class);
    }
    foreach ([[INF, 0], [NAN, 0], [-0.1, 0], [10.1, 0], [0, 5.1]] as [$x, $y]) {
        expect(fn() => $projection->projectPoint($x, $y))->toThrow(InvalidArgumentException::class);
    }
});
