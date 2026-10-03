<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\EventCue;

/** An east-edge door two cells tall and a separate south exit: one marker, two placements. */
function makeScatteredArea(): CellArea
{
  return CellArea::fromCells([[9, 3], [9, 4], [4, 8], [5, 8]]);
}

it('holds exactly its cells, in reading order, and nothing between them', function () {
  $area = makeScatteredArea();

  expect($area->cells)->toBe([[9, 3], [9, 4], [4, 8], [5, 8]])
    ->and($area->bounds->getX())->toBe(4)->and($area->bounds->getY())->toBe(3)
    ->and($area->bounds->getWidth())->toBe(6)->and($area->bounds->getHeight())->toBe(6)
    ->and($area->isRectangle)->toBeFalse()
    ->and($area->firstCell)->toEqual(new Vector2(9, 3))
    ->and($area->contains(new Vector2(9, 4)))->toBeTrue()
    ->and($area->contains(new Vector2(5.5, 8.25)))->toBeTrue()
    ->and($area->contains(new Vector2(6, 6)))->toBeFalse()
    ->and($area->containsCell(4, 3))->toBeFalse();
});

it('covers every cell of a rectangle exactly as the rectangle does', function () {
  $rect = new Rect(2, 1, 3, 2);
  $area = CellArea::fromRect($rect);

  expect($area->isRectangle)->toBeTrue()
    ->and($area->firstCell)->toEqual(new Vector2(2, 1))
    ->and(count($area->cells))->toBe(6);

  for ($y = 0; $y < 5; $y++) {
    for ($x = 0; $x < 7; $x++) {
      expect($area->contains(new Vector2($x, $y)))->toBe($rect->contains(new Vector2($x, $y)), "cell {$x},{$y}");
    }
  }
});

it('reads exact cells or a rectangle, and round trips its cells', function () {
  expect(CellArea::fromArray(['cells' => [[1, 1], [3, 1]]])->cells)->toBe([[1, 1], [3, 1]])
    ->and(CellArea::fromArray(['x' => 1, 'y' => 1, 'width' => 2, 'height' => 1])->cells)->toBe([[1, 1], [2, 1]])
    ->and(CellArea::fromArray(makeScatteredArea()->toArray())->cells)->toBe(makeScatteredArea()->cells)
    ->and(CellArea::fromCells([[1, 1], [1, 1]])->cells)->toBe([[1, 1]]);
});

it('refuses an area with no cells or malformed ones', function (mixed $area, string $message) {
  expect(fn() => CellArea::fromArray($area, 'Event area'))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'no cells' => [['cells' => []], 'Event area: An area needs at least one cell.'],
  'not a pair' => [['cells' => [[1]]], 'Event area: An area cell must be [x, y] with whole numbers.'],
  'fractional' => [['cells' => [[1.5, 2]]], 'An area cell must be [x, y] with whole numbers.'],
  'neither shape' => [['x' => 1, 'y' => 1], 'Event area needs cells as a list of [x, y], or whole-number x, y, width and height.'],
  'empty rectangle' => [['x' => 1, 'y' => 1, 'width' => 0, 'height' => 1], 'Event area needs cells'],
]);

it('splits into its separate placements, each centred on its own cells', function () {
  $pieces = makeScatteredArea()->findPieces();
  $ring = CellArea::fromCells([[0, 0], [1, 0], [2, 0], [0, 1], [2, 1], [0, 2], [1, 2], [2, 2]]);

  expect(array_map(static fn(CellArea $piece): array => $piece->cells, $pieces))->toBe([[[9, 3], [9, 4]], [[4, 8], [5, 8]]])
    ->and(array_map(static fn(CellArea $piece): Vector2 => $piece->findCenterCell(), $pieces))->toEqual([new Vector2(9, 3), new Vector2(4, 8)])
    ->and(CellArea::fromRect(new Rect(4, 6, 3, 3))->findCenterCell())->toEqual(new Vector2(5, 7))
    // A ring's middle is not one of its cells; the cue stays on the ring.
    ->and($ring->findPieces())->toHaveCount(1)
    ->and($ring->containsCell(...array_map('intval', [$ring->findCenterCell()->x, $ring->findCenterCell()->y])))->toBeTrue();
});

it('cues each separate placement of a trigger once', function () {
  $cue = new EventCue('!', 'bright-yellow');

  expect($cue->findPositions(makeScatteredArea()))->toEqual([new Vector2(9, 3), new Vector2(4, 8)])
    ->and($cue->findPositions(CellArea::fromRect(new Rect(4, 6, 3, 3))))->toEqual([new Vector2(5, 7)]);
});
