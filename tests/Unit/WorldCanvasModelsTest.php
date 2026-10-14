<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;

function createWorldCanvasModel(string $id = 'group', int $x = 0, int $y = 0, int $layer = 0): PresentationWorldCanvas
{
  return new PresentationWorldCanvas(id: $id, x: $x, y: $y, pivot: new PresentationSpritePivot(0.25, 0.75),
    canvas: new PresentationCanvas(10, 8), layer: $layer);
}

it('serializes precisely the world group fields without inventing global order or gameplay fields', function () {
  $group = createWorldCanvasModel('group', -12, 8, -10);
  expect($group->toArray())->toBe(['id' => 'group', 'x' => -12, 'y' => 8,
    'pivot' => ['x' => 0.25, 'y' => 0.75], 'layer' => -10,
    'canvas' => ['width' => 10, 'height' => 8, 'images' => [], 'indicators' => [], 'textLayers' => []]])
    ->and(new PresentationWorldCanvas('default-layer', 0, 0, $group->pivot, $group->canvas)->layer)->toBe(0);
  expect(fn() => new ReflectionProperty($group, 'x')->setValue($group, 1))->toThrow(Error::class);
  expect(fn() => new PresentationWorldCanvas(id: 'missing-pivot', x: 0, y: 0, canvas: $group->canvas))
    ->toThrow(ArgumentCountError::class);
});

it('accepts signed 32 bit anchor and layer boundaries and rejects every overflow', function () {
  foreach ([-2147483648, 2147483647] as $value) {
    expect(createWorldCanvasModel(x: $value, y: $value, layer: $value)->x)->toBe($value);
  }
  foreach ([-2147483649, 2147483648, PHP_INT_MIN, PHP_INT_MAX] as $value) {
    foreach (['x', 'y', 'layer'] as $field) {
      expect(fn() => createWorldCanvasModel(...[$field => $value]))->toThrow(InvalidArgumentException::class);
    }
  }
  foreach (['', str_repeat('x', 257), "group\n", "group\0", "\xFF"] as $id) {
    expect(fn() => createWorldCanvasModel($id))->toThrow(InvalidArgumentException::class);
  }
});

it('projects signed world anchors into camera relative coordinates without modifying shared local models', function () {
  $group = createWorldCanvasModel(x: -10, y: 15, layer: 8);
  $projected = $group->createProjected(-15, 20);
  expect($projected->x)->toBe(5)->and($projected->y)->toBe(-5)
    ->and($projected->id)->toBe($group->id)->and($projected->layer)->toBe(8)
    ->and($projected->pivot)->toBe($group->pivot)->and($projected->canvas)->toBe($group->canvas)
    ->and($group->x)->toBe(-10)->and($group->y)->toBe(15);
  expect(createWorldCanvasModel(x: -2147483648, y: 2147483647)->createProjected(0, 0)->x)->toBe(-2147483648);
  foreach ([[1, 0], [0, -1], [2147483647, -2147483648], [PHP_INT_MAX, 0], [0, PHP_INT_MIN]] as [$x, $y]) {
    expect(fn() => createWorldCanvasModel(x: -2147483648, y: 2147483647)->createProjected($x, $y))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('orders whole subjects by layer then row keeping ties stable and copying caller reference aliases', function () {
  $first = createWorldCanvasModel('first', 50, 7, 2);
  $tie = createWorldCanvasModel('tie', -50, 7, 2);
  $front = createWorldCanvasModel('front', 0, 8, 2);
  $back = createWorldCanvasModel('back', 0, -1, 2);
  $low = createWorldCanvasModel('low', 0, 99, -1);
  $list = [&$first, $tie, $front, $back, $low];
  $ordered = PresentationWorldCanvas::getOrderedList($list);
  $first = createWorldCanvasModel('replacement');
  $list[] = $first;
  expect(array_column($ordered, 'id'))->toBe(['low', 'back', 'first', 'tie', 'front']);
});

it('requires typed unique bounded world lists without conflating child identifiers across roots', function () {
  $groups = array_map(fn($i) => createWorldCanvasModel('group-' . $i), range(1, 1024));
  expect(PresentationWorldCanvas::getOrderedList($groups))->toHaveCount(1024)
    ->and(PresentationWorldCanvas::getOrderedList([]))->toBe([]);
  foreach ([[...$groups, createWorldCanvasModel('extra')], [$groups[0], $groups[0]],
    ['named' => $groups[0]], [new stdClass()], [new PresentationCanvas(1, 1)]] as $list) {
    expect(fn() => PresentationWorldCanvas::getOrderedList($list))->toThrow(InvalidArgumentException::class);
  }
});
