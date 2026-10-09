<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Presentation\MenuPagination;

it('packs stable pages from the beginning without taking a counter row from the viewport', function () {
  $pages = new MenuPagination(array_fill(0, 7, 140), 560);
  expect($pages->pages)->toBe([['first' => 0, 'last' => 3], ['first' => 4, 'last' => 6]]);
  foreach (range(0, 6) as $index) { expect($pages->getPageIndex($index))->toBe($index < 4 ? 0 : 1); }
});

it('fits variable-height records wholly and preserves position when changing pages', function () {
  $pages = new MenuPagination([180, 140, 140, 140, 140, 140, 140], 560);
  expect($pages->pages)->toBe([['first' => 0, 'last' => 2], ['first' => 3, 'last' => 6]])
    ->and($pages->getAdjacentRecordIndex(2, 1))->toBe(5)
    ->and($pages->getAdjacentRecordIndex(6, -1))->toBe(2)
    ->and($pages->getAdjacentRecordIndex(0, -1))->toBe(0)
    ->and($pages->getAdjacentRecordIndex(6, 1))->toBe(6);
  $short = new MenuPagination(array_fill(0, 5, 140), 560);
  expect($short->getAdjacentRecordIndex(3, 1))->toBe(4);
});

it('refuses invalid page geometry instead of clipping or hiding a record', function (array $heights, float $height) {
  expect(fn() => new MenuPagination($heights, $height))->toThrow(InvalidArgumentException::class);
})->with([
  [[], 560], [[0], 560], [[-1], 560], [[561], 560], [[140], 0], [[140], INF], [[1 => 140], 560],
]);

it('refuses unknown records and non-page navigation directions', function () {
  $pages = new MenuPagination([140], 560);
  expect(fn() => $pages->getPageIndex(-1))->toThrow(InvalidArgumentException::class);
  expect(fn() => $pages->getPageIndex(1))->toThrow(InvalidArgumentException::class);
  expect(fn() => $pages->getAdjacentRecordIndex(0, 0))->toThrow(InvalidArgumentException::class);
});
