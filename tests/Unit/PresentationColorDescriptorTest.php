<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

it('hydrates all shared color kinds at their exact integer boundaries', function (array $data) {
  $color = PresentationColor::createFromArray($data);
  expect($color->toArray())->toBe($data)
    ->and(PresentationColor::createFromArray($color->toArray()))->toEqual($color);
})->with([
  [['kind' => 'rgb', 'r' => 0, 'g' => 128, 'b' => 255]],
  [['kind' => 'rgb', 'r' => 255, 'g' => 0, 'b' => 0]],
  [['kind' => 'rgb', 'r' => 0, 'g' => 255, 'b' => 0]],
  [['kind' => 'ansi16', 'index' => 0]], [['kind' => 'ansi16', 'index' => 15]],
  [['kind' => 'ansi256', 'index' => 0]], [['kind' => 'ansi256', 'index' => 255]],
]);

it('rejects malformed shared color shapes component coercion and bounds without defaults', function (mixed $data) {
  expect(fn() => PresentationColor::createFromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $cases = [null, false, true, 1, 'color', [], new stdClass(), PresentationColor::rgb(1, 2, 3),
    ['kind' => null], ['kind' => 0], ['kind' => false], ['kind' => new stdClass()],
    ['kind' => 'RGB', 'r' => 1, 'g' => 2, 'b' => 3],
    ['kind' => 'rgba', 'r' => 1, 'g' => 2, 'b' => 3, 'a' => 1]];
  foreach ([['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3],
    ['kind' => 'ansi16', 'index' => 1], ['kind' => 'ansi256', 'index' => 1]] as $base) {
    $cases[] = array_values($base);
    $cases[] = [...$base, 'alpha' => 1];
    $cases[] = [...$base, 0 => 1];
    foreach (array_keys($base) as $field) {
      $missing = $base; unset($missing[$field]); $cases[] = $missing;
      if ($field === 'kind') { continue; }
      foreach ([null, false, true, '1', 1.0, [], new stdClass(), NAN, INF, -INF, -1, 256, PHP_INT_MAX] as $value) {
        $cases[] = array_replace($base, [$field => $value]);
      }
    }
  }
  $cases[] = ['kind' => 'ansi16', 'index' => 16];
  $cases[] = ['kind' => 'ansi16', 'index' => 0, 'r' => 0];
  return array_map(static fn(mixed $value): array => [$value], $cases);
});

it('detaches referenced color kind and components from admitted and exported descriptors', function () {
  $kind = 'rgb'; $red = 10; $green = 20; $blue = 30;
  $data = ['kind' => &$kind, 'r' => &$red, 'g' => &$green, 'b' => &$blue];
  $color = PresentationColor::createFromArray($data);
  $before = $color->toArray();
  $kind = 'ansi16'; $red = 255; $green = 0; $blue = 255;
  $exported = $color->toArray();
  $exported['kind'] = 'ansi256'; $exported['r'] = 0;
  expect($color->toArray())->toBe($before);

  $index = 5;
  $indexed = PresentationColor::createFromArray(['kind' => 'ansi16', 'index' => &$index]);
  $index = 15;
  expect($indexed->toArray())->toBe(['kind' => 'ansi16', 'index' => 5]);
});
