<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

function getGlyphEffectsDescriptor(): array
{
  return [
    'outline' => ['width' => 0.75, 'color' => ['kind' => 'ansi16', 'index' => 0]],
    'shadow' => ['offsetX' => 1.25, 'offsetY' => -2.5, 'sigma' => 0.5, 'opacity' => 0.65,
      'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]],
  ];
}

it('hydrates the exact exported glyph descriptor with no lost fractional intent', function () {
  $data = getGlyphEffectsDescriptor();
  $effects = CanvasGlyphEffects::createFromArray($data);
  expect($effects)->toEqual(new CanvasGlyphEffects(0.75, PresentationColor::ansi16(0),
    1.25, -2.5, 0.5, 0.65, PresentationColor::rgb(1, 2, 3)))
    ->and($effects->toArray())->toBe($data)
    ->and(CanvasGlyphEffects::createFromArray($effects->toArray())->toArray())->toBe($data)
    ->and($effects->padding())->toBe(['left' => 3, 'top' => 6, 'right' => 4, 'bottom' => 3]);
});

it('accepts explicit zero effects and integer numbers without inventing missing defaults', function () {
  $data = ['outline' => ['width' => 0, 'color' => ['kind' => 'ansi256', 'index' => 255]],
    'shadow' => ['offsetX' => 0, 'offsetY' => 0, 'sigma' => 0, 'opacity' => 0,
      'color' => ['kind' => 'rgb', 'r' => 0, 'g' => 128, 'b' => 255]]];
  $effects = CanvasGlyphEffects::createFromArray($data);
  expect($effects->toArray())->toBe([
    'outline' => ['width' => 0.0, 'color' => $data['outline']['color']],
    'shadow' => ['offsetX' => 0.0, 'offsetY' => 0.0, 'sigma' => 0.0, 'opacity' => 0.0,
      'color' => $data['shadow']['color']],
  ])->and($effects->padding())->toBe(['left' => 0, 'top' => 0, 'right' => 0, 'bottom' => 0]);
});

it('uses the constructor bounds authority for every declarative numeric field', function (
  string $section, string $field, float $low, float $high,
) {
  foreach ([$low, $high] as $value) {
    $data = getGlyphEffectsDescriptor();
    $data[$section][$field] = $value;
    expect(CanvasGlyphEffects::createFromArray($data)->toArray()[$section][$field])->toBe($value);
  }
  foreach ([$low - 0.000001, $high + 0.000001, -PHP_FLOAT_MAX, PHP_FLOAT_MAX, NAN, INF, -INF] as $value) {
    $data = getGlyphEffectsDescriptor();
    $data[$section][$field] = $value;
    expect(fn() => CanvasGlyphEffects::createFromArray($data))->toThrow(InvalidArgumentException::class);
  }
})->with([
  ['outline', 'width', 0.0, 4.0], ['shadow', 'offsetX', -8.0, 8.0],
  ['shadow', 'offsetY', -8.0, 8.0], ['shadow', 'sigma', 0.0, 4.0], ['shadow', 'opacity', 0.0, 1.0],
]);

it('rejects coercible and nonnumeric declarative glyph values before construction', function (
  string $section, string $field, mixed $value,
) {
  $data = getGlyphEffectsDescriptor();
  $data[$section][$field] = $value;
  expect(fn() => CanvasGlyphEffects::createFromArray($data))
    ->toThrow(InvalidArgumentException::class, 'without coercion');
})->with([['outline', 'width'], ['shadow', 'offsetX'], ['shadow', 'offsetY'], ['shadow', 'sigma'], ['shadow', 'opacity']])
  ->with(['numeric string' => '1', 'true' => true, 'false' => false, 'null' => null,
    'array' => [[]], 'object' => new stdClass()]);

it('requires exact plain glyph sections and every field with no unknown keys', function (mixed $data) {
  expect(fn() => CanvasGlyphEffects::createFromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = getGlyphEffectsDescriptor();
  $cases = [null, false, true, 1, 'effects', new stdClass(), [], array_values($base),
    [...$base, 'extra' => 1], [...$base, 0 => 1], CanvasGlyphEffects::createFromArray($base)];
  foreach (['outline', 'shadow'] as $section) {
    $missing = $base; unset($missing[$section]); $cases[] = $missing;
    foreach ([null, false, 1, 'section', new stdClass(), [], array_values($base[$section])] as $value) {
      $cases[] = array_replace($base, [$section => $value]);
    }
    foreach (array_keys($base[$section]) as $field) {
      $missing = $base; unset($missing[$section][$field]); $cases[] = $missing;
    }
    $unknown = $base; $unknown[$section]['extra'] = 1; $cases[] = $unknown;
    $unknown = $base; $unknown[$section][0] = 1; $cases[] = $unknown;
  }
  return array_map(static fn(mixed $value): array => [$value], $cases);
});

it('requires shared strict color descriptors for both glyph color roles', function (string $section, mixed $color) {
  $data = getGlyphEffectsDescriptor();
  $data[$section]['color'] = $color;
  expect(fn() => CanvasGlyphEffects::createFromArray($data))->toThrow(InvalidArgumentException::class);
})->with(['outline', 'shadow'])->with([
  'null' => null, 'string' => 'black', 'typed object' => PresentationColor::ansi16(0),
  'missing component' => [['kind' => 'rgb', 'r' => 1, 'g' => 2]],
  'coercible index' => [['kind' => 'ansi16', 'index' => '0']],
  'unknown component' => [['kind' => 'ansi16', 'index' => 0, 'alpha' => 1]],
  'out of bounds' => [['kind' => 'ansi256', 'index' => 256]],
]);

it('detaches referenced glyph sections numeric fields and nested colors on input and export', function () {
  $width = 1; $offsetX = -2; $offsetY = 3; $sigma = 0.25; $opacity = 0.75;
  $index = 7; $red = 10; $green = 20; $blue = 30;
  $outlineColor = ['kind' => 'ansi16', 'index' => &$index];
  $shadowColor = ['kind' => 'rgb', 'r' => &$red, 'g' => &$green, 'b' => &$blue];
  $outline = ['width' => &$width, 'color' => &$outlineColor];
  $shadow = ['offsetX' => &$offsetX, 'offsetY' => &$offsetY, 'sigma' => &$sigma,
    'opacity' => &$opacity, 'color' => &$shadowColor];
  $data = ['outline' => &$outline, 'shadow' => &$shadow];
  $effects = CanvasGlyphEffects::createFromArray($data);
  $before = $effects->toArray();
  $width = 4; $offsetX = 8; $offsetY = -8; $sigma = 4; $opacity = 0;
  $index = 15; $red = 255; $green = 0; $blue = 255;
  $outlineColor = []; $shadowColor = []; $outline = []; $shadow = [];
  $exported = $effects->toArray();
  $exported['outline']['width'] = 0;
  $exported['outline']['color']['index'] = 0;
  $exported['shadow']['opacity'] = 1;
  $exported['shadow']['color']['r'] = 0;
  expect($effects->toArray())->toBe($before)
    ->and($effects->outlineWidth)->toBe(1.0)->and($effects->shadowOffsetX)->toBe(-2.0)
    ->and($effects->shadowOffsetY)->toBe(3.0)->and($effects->shadowSigma)->toBe(0.25)
    ->and($effects->shadowOpacity)->toBe(0.75);
});
