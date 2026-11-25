<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationFrame;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;

it('encodes typed text and sprites using the existing FRAME envelope', function () {
  $sprite = new PresentationSprite('test', 'Graphics/test.png', 8, 4, 32, 48, layer: 100);
  $message = new PresentationFrame(1, ['map', ' @ '], [$sprite])->toRendererMessage();
  expect($message->type)->toBe(RendererMessageType::FRAME)
    ->and(json_decode($message->encode(), true))->toBe([
      'protocol' => 1, 'type' => 'frame', 'frame' => 1, 'text' => ['map', ' @ '],
      'sprites' => [['id' => 'test', 'asset' => 'Graphics/test.png', 'x' => 8, 'y' => 4,
        'width' => 32, 'height' => 48, 'anchor' => 'bottom_center', 'layer' => 100]],
    ])->and(PresentationSpriteAnchor::cases())->toBe([PresentationSpriteAnchor::BOTTOM_CENTER]);
});

it('allows empty atomic replacements and PHP integer frame limits', function () {
  foreach ([0, PHP_INT_MAX] as $number) {
    expect(new PresentationFrame($number)->toRendererMessage()->payload)
      ->toBe(['frame' => $number, 'text' => [], 'sprites' => []]);
  }
});

it('orders sprites by layer, then by row so lower sprites draw in front, and preserves author order for ties', function () {
  $front = new PresentationSprite('front', 'front.png', 0, 0, 1, 1, layer: 100);
  $lower = new PresentationSprite('lower', 'lower.png', 0, 5, 48, 48, layer: -2);
  $first = new PresentationSprite('first', 'first.png', -1, 2, 4096, 4096, layer: -2);
  $upper = new PresentationSprite('upper', 'upper.png', 3, -2, 48, 48, layer: -2);
  $second = new PresentationSprite('second', 'second.png', 0, 2, 1, 1, layer: -2);
  // A higher layer wins regardless of row; within a layer, rows ascend; equal rows keep author order.
  expect(new PresentationFrame(1, [], [$front, $lower, $first, $upper, $second])->sprites)
    ->toBe([$upper, $first, $second, $lower, $front]);
});

it('rejects invalid sprite structure without filesystem access', function ($overrides) {
  $args = array_replace(['id' => 'test', 'asset' => 'missing-but-structurally-valid.png',
    'x' => 0, 'y' => 0, 'width' => 32, 'height' => 48], $overrides);
  expect(fn() => new PresentationSprite(...$args))->toThrow(InvalidArgumentException::class);
})->with([
  'empty identity' => [['id' => '']],
  'NUL in identity' => [['id' => "bad\0id"]],
  'invalid UTF-8 in identity' => [['id' => "\xFF"]],
  'empty asset' => [['asset' => '']],
  'absolute asset path' => [['asset' => '/tmp/test.png']],
  'drive absolute asset path' => [['asset' => 'C:/test.png']],
  'drive relative asset path' => [['asset' => 'C:test.png']],
  'network asset path' => [['asset' => '\\server\test.png']],
  'parent asset path' => [['asset' => '../test.png']],
  'embedded parent asset path' => [['asset' => 'sub/../test.png']],
  'backslash asset separator' => [['asset' => 'sub\..\test.png']],
  'NUL in asset path' => [['asset' => "bad\0.png"]],
  'invalid UTF-8 in asset path' => [['asset' => "\xFF"]],
  'web asset URL' => [['asset' => 'https://example.com/test.png']],
  'zero width' => [['width' => 0]],
  'negative height' => [['height' => -1]],
  'width over limit' => [['width' => 4097]],
  'height over limit' => [['height' => 4097]],
  'x above limit' => [['x' => 2147483648]],
  'y below limit' => [['y' => -2147483649]],
  'layer above limit' => [['layer' => 2147483648]],
]);

it('accepts signed coordinate and layer limits and leaves PNG validation to the renderer', function () {
  $sprite = new PresentationSprite('test', 'not-present.no-extension', -2147483648, 2147483647, 1, 4096, layer: -2147483648);
  expect($sprite->asset)->toBe('not-present.no-extension')->and($sprite->x)->toBe(-2147483648);
});

it('rejects invalid frame text and identifiers', function ($number, $text) {
  expect(fn() => new PresentationFrame($number, $text))->toThrow(InvalidArgumentException::class);
})->with([
  'negative frame number' => [-1, []],
  'nonsequential rows' => [1, [1 => 'x']],
  'integer row' => [1, [123]],
  'invalid UTF-8' => [1, ["\xFF"]],
  'ANSI foreground' => [1, ["\033[31mx"]],
  'tab' => [1, ["a\tb"]],
  'line feed' => [1, ["a\nb"]],
  'carriage return' => [1, ["a\rb"]],
  'NUL' => [1, ["\0"]],
  'delete' => [1, ["\x7F"]],
  'next line' => [1, ["\u{85}"]],
  'row over limit' => [1, [str_repeat('x', 513)]],
  'row count over limit' => [1, array_fill(0, 257, '')],
]);

it('measures renderer text in Unicode scalars rather than bytes or terminal columns', function () {
  $row = str_repeat("\u{1F408}", 512);
  expect(new PresentationFrame(1, [$row])->text)->toBe([$row]);
});

it('rejects duplicate IDs non-list collections and untyped sprite entries', function () {
  $sprite = new PresentationSprite('test', 'test.png', 0, 0, 1, 1);
  foreach ([[$sprite, clone $sprite], [1 => $sprite], [new stdClass()]] as $sprites) {
    expect(fn() => new PresentationFrame(1, [], $sprites))->toThrow(InvalidArgumentException::class);
  }
});

it('accepts the sprite count limit and rejects overflow', function () {
  $sprites = [];
  for ($i = 0; $i < 1024; $i++) {
    $sprites[] = new PresentationSprite((string) $i, 'test.png', 0, 0, 1, 1);
  }
  expect(new PresentationFrame(1, [], $sprites)->sprites)->toHaveCount(1024);
  $sprites[] = new PresentationSprite('overflow', 'test.png', 0, 0, 1, 1);
  expect(fn() => new PresentationFrame(1, [], $sprites))->toThrow(InvalidArgumentException::class, '1024');
});

it('keeps frame and sprite data immutable even when callers supply array references', function () {
  $row = 'original';
  $sprite = new PresentationSprite('one', 'one.png', 0, 0, 1, 1);
  $frame = new PresentationFrame(1, [&$row], [&$sprite]);
  $original = $frame->sprites[0];
  $row = 'changed';
  $sprite = new PresentationSprite('two', 'two.png', 1, 0, 1, 1);
  expect($frame->text)->toBe(['original'])->and($frame->sprites)->toBe([$original]);
  expect(function () use ($frame) { $frame->text[0] = 'changed'; })->toThrow(Error::class);
  expect(function () use ($original) { $original->x = 9; })->toThrow(Error::class);
});
