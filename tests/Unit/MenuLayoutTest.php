<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Presentation\MenuLayout;

it('centers the common menu envelope and retains margins on smaller canvases', function (int $width, int $height, array $expected) {
  $bounds = MenuLayout::getBounds($width, $height);
  expect([$bounds->x, $bounds->y, $bounds->width, $bounds->height])->toBe($expected)
    ->and($bounds->x * 2 + $bounds->width)->toBe((float)$width)
    ->and($bounds->y * 2 + $bounds->height)->toBe((float)$height);
})->with([
  [1350, 720, [125.0, 10.0, 1100.0, 700.0]],
  [1600, 1000, [250.0, 150.0, 1100.0, 700.0]],
  [1000, 600, [10.0, 10.0, 980.0, 580.0]],
]);

it('centers a compact menu through the same envelope calculation', function () {
  $bounds = MenuLayout::getBounds(1350, 720, 560);
  expect([$bounds->x, $bounds->y, $bounds->width, $bounds->height])->toBe([125.0, 80.0, 1100.0, 560.0]);
});
