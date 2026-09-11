<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationFrame;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;

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

it('orders sprites by layer and preserves author order for ties', function () {
  $front = new PresentationSprite('front', 'front.png', 0, 0, 1, 1, layer: 100);
  $first = new PresentationSprite('first', 'first.png', -1, -2, 4096, 4096, layer: -2);
  $second = new PresentationSprite('second', 'second.png', 0, 0, 1, 1, layer: -2);
  expect(new PresentationFrame(1, [], [$front, $first, $second])->sprites)->toBe([$first, $second, $front]);
});

it('rejects invalid sprite structure without filesystem access', function ($overrides) {
  $args = array_replace(['id' => 'test', 'asset' => 'missing-but-structurally-valid.png',
    'x' => 0, 'y' => 0, 'width' => 32, 'height' => 48], $overrides);
  expect(fn() => new PresentationSprite(...$args))->toThrow(InvalidArgumentException::class);
})->with([
  [['id' => '']], [['id' => "bad\0id"]], [['id' => "\xFF"]], [['asset' => '']],
  [['asset' => '/tmp/test.png']], [['asset' => 'C:/test.png']], [['asset' => 'C:test.png']],
  [['asset' => '\\server\test.png']], [['asset' => '../test.png']], [['asset' => 'sub/../test.png']],
  [['asset' => 'sub\..\test.png']], [['asset' => "bad\0.png"]], [['asset' => "\xFF"]],
  [['asset' => 'https://example.com/test.png']], [['width' => 0]], [['height' => -1]],
  [['width' => 4097]], [['height' => 4097]], [['x' => 2147483648]], [['y' => -2147483649]], [['layer' => 2147483648]],
]);

it('accepts signed coordinate and layer limits and leaves PNG validation to the renderer', function () {
  $sprite = new PresentationSprite('test', 'not-present.no-extension', -2147483648, 2147483647, 1, 4096, layer: -2147483648);
  expect($sprite->asset)->toBe('not-present.no-extension')->and($sprite->x)->toBe(-2147483648);
});

it('rejects invalid frame text and identifiers', function ($number, $text) {
  expect(fn() => new PresentationFrame($number, $text))->toThrow(InvalidArgumentException::class);
})->with([[-1, []], [1, [1 => 'x']], [1, [123]], [1, ["\xFF"]], [1, ["\033[31mx"]],
  [1, ["a\tb"]], [1, ["a\nb"]], [1, ["a\rb"]], [1, ["\0"]], [1, ["\x7F"]], [1, ["\u{85}"]],
  [1, [str_repeat('x', 513)]], [1, array_fill(0, 257, '')]]);

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
