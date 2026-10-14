<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\Text\FontReference;
use Ichiloto\Engine\Rendering\Presentation\Text\TextBounds;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMetrics;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;

function makeTextModelSpec(array $replace = []): TextSpec
{
  return new TextSpec(...array_replace([
    'text' => 'Synthetic label', 'fonts' => [new FontReference('synthetic/missing.ttf')],
    'fontSize' => 12.5, 'locale' => 'en', 'foreground' => PresentationColor::rgb(50, 30, 20),
  ], $replace));
}

function makeTextModelMetrics(): TextMetrics
{
  return new TextMetrics(24.5, 10, new TextBounds(0, 0, 24.5, 15),
    new TextBounds(-1.25, -0.5, 25.5, 12), new TextBounds(-3, -2, 29, 17));
}

/** Malformed UTF-8 and controls are fixture bytes, never XML report labels. */
function nameTextModelCases(array $cases): array
{
  $named = [];
  foreach ($cases as $index => $case) { $named['case-' . $index] = $case; }
  return $named;
}

it('serializes replaceable font references without opening or decoding project files', function () {
  expect(new FontReference('synthetic/missing.ttf')->toArray())
    ->toBe(['asset' => 'synthetic/missing.ttf', 'faceIndex' => 0])
    ->and(new FontReference('synthetic/collection.otc', 31)->toArray())
    ->toBe(['asset' => 'synthetic/collection.otc', 'faceIndex' => 31])
    ->and(new FontReference(str_repeat('a', 4096))->asset)->toHaveLength(4096);
});

it('rejects unsafe or oversized font asset paths through the shared path policy', function (string $asset) {
  expect(fn() => new FontReference($asset))->toThrow(InvalidArgumentException::class);
})->with(nameTextModelCases([
  '', '/fonts/a.ttf', '../a.ttf', 'fonts/../a.ttf', 'C:/fonts/a.ttf', 'C:\\fonts\\a.ttf',
  'https://example.test/a.ttf', 'fonts\\a.ttf', "fonts/a\0.ttf", "fonts/\xFF.ttf", str_repeat('a', 4097),
]));

it('bounds explicit collection face indices', function (int $index) {
  expect(fn() => new FontReference('synthetic/font.ttf', $index))->toThrow(InvalidArgumentException::class);
})->with([-1, 32, PHP_INT_MAX]);

it('emits exactly the project text wire specification and preserves ordered fallback faces', function () {
  $fonts = [new FontReference('synthetic/first.ttf', 1), new FontReference('synthetic/second.otf')];
  $effects = new CanvasGlyphEffects(0.5, PresentationColor::ansi16(0), -1, 2, 0.25, 0.75, PresentationColor::rgb(1, 2, 3));
  $plain = makeTextModelSpec(['fonts' => $fonts]);
  expect($plain->toArray())->toBe([
    'text' => 'Synthetic label', 'fonts' => [['asset' => 'synthetic/first.ttf', 'faceIndex' => 1],
      ['asset' => 'synthetic/second.otf', 'faceIndex' => 0]],
    'fontSize' => 12.5, 'locale' => 'en', 'foreground' => ['kind' => 'rgb', 'r' => 50, 'g' => 30, 'b' => 20],
  ])->and(makeTextModelSpec(['fonts' => $fonts, 'glyphEffects' => $effects])->toArray())
    ->toBe([...$plain->toArray(), 'glyphEffects' => $effects->toArray()]);
});

it('detaches caller-owned font references without changing fallback order or forbidding repeated faces', function () {
  $first = new FontReference('synthetic/a.ttf');
  $fonts = [&$first, $first];
  $spec = makeTextModelSpec(['fonts' => $fonts]);
  $first = new FontReference('synthetic/replacement.otf');
  $fonts[] = $first;
  expect(array_column($spec->toArray()['fonts'], 'asset'))->toBe(['synthetic/a.ttf', 'synthetic/a.ttf']);
  expect(fn() => new ReflectionProperty($spec, 'fonts')->setValue($spec, [$first]))->toThrow(Error::class);
});

it('requires a typed bounded list of fonts', function (array $fonts) {
  expect(fn() => makeTextModelSpec(['fonts' => $fonts]))->toThrow(InvalidArgumentException::class);
})->with(fn() => [
  [[]], [['first' => new FontReference('synthetic/a.ttf')]], [[1 => new FontReference('synthetic/a.ttf')]],
  [[null]], [['synthetic/a.ttf']], [array_fill(0, 17, new FontReference('synthetic/a.ttf'))],
]);

it('accepts both font count and size boundaries', function () {
  foreach ([1.0, 256.0] as $size) {
    $spec = makeTextModelSpec(['fonts' => array_fill(0, 16, new FontReference('synthetic/a.ttf')), 'fontSize' => $size]);
    expect($spec->fontSize)->toBe($size)->and($spec->fonts)->toHaveCount(16);
  }
});

it('rejects nonfinite and out of range text sizes', function (float $size) {
  expect(fn() => makeTextModelSpec(['fontSize' => $size]))->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, 0.999, 256.001, INF, -INF, NAN]);

it('validates locale bytes and controls without inventing language-tag or trimming policy', function () {
  foreach ([' ', 'x-private', str_repeat("\u{00E9}", 64)] as $locale) {
    expect(makeTextModelSpec(['locale' => $locale])->locale)->toBe($locale);
  }
});

it('rejects invalid text locales', function (string $locale) {
  expect(fn() => makeTextModelSpec(['locale' => $locale]))->toThrow(InvalidArgumentException::class);
})->with(nameTextModelCases(['', str_repeat('a', 129), str_repeat("\u{00E9}", 65), "en\n", "en\t", "en\0", "en\x7F", "en\u{0085}", "\xFF"]));

it('preserves Unicode and newlines including empty text at the current scalar and line boundaries', function () {
  foreach (['', "A\u{0301} \u{05D0}\u{05D1}\n\u{0627}\u{0644}",
    str_repeat("\u{00E9}", 524288), str_repeat("\n", 32767)] as $text) {
    expect(makeTextModelSpec(['text' => $text])->toArray()['text'])->toBe($text);
  }
});

it('rejects text controls except newline and rejects malformed UTF-8 or excessive lines and scalars', function (string $text) {
  expect(fn() => makeTextModelSpec(['text' => $text]))->toThrow(InvalidArgumentException::class);
})->with(fn() => nameTextModelCases(["A\tB", "A\rB", "A\0B", "A\x7FB", "A\u{0085}B", "\xFF", "\xC0\xAF",
  str_repeat('a', 524289), str_repeat("\n", 32768)]));

it('round trips signed bounds and legitimate empty metrics without canvas rectangle restrictions', function () {
  foreach ([['x' => -32768, 'y' => 32768, 'width' => 0, 'height' => 16384],
    ['x' => -1.5, 'y' => -2.25, 'width' => 16384, 'height' => 0]] as $data) {
    $bounds = TextBounds::fromArray($data);
    expect($bounds->toArray())->toBe(array_map(static fn($number): float => (float)$number, $data));
  }
  expect(new TextBounds(0, 0, 0, 0)->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0]);
});

it('strictly rejects malformed bounds fields and numeric coercion', function (array $data) {
  expect(fn() => TextBounds::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
  $cases = [[[]], [[0, 0, 0, 0]], [[...$base, 'other' => 1]]];
  foreach (array_keys($base) as $field) {
    $missing = $base; unset($missing[$field]); $cases[] = [$missing];
    foreach (['1', true, false, null, [], new stdClass(), INF, -INF, NAN] as $value) {
      $cases[] = [array_replace($base, [$field => $value])];
    }
  }
  foreach (['width', 'height'] as $field) {
    foreach ([16384.1, -16384.1] as $value) {
      $cases[] = [array_replace($base, [$field => $value])];
    }
  }
  $cases[] = [array_replace($base, ['width' => -0.01])];
  $cases[] = [array_replace($base, ['height' => -0.01])];
  return $cases;
});

it('validates bounds for direct typed construction as well as wire decoding', function (array $numbers) {
  expect(fn() => new TextBounds(...$numbers))->toThrow(InvalidArgumentException::class);
})->with([[[INF, 0, 0, 0]], [[0, NAN, 0, 0]], [[0, 0, -1, 0]], [[0, 0, 0, -1]], [[0, 0, 0, 16385]], [[0, 0, 16385, 0]]]);

it('round trips every measured field without persistent font or layout identity', function () {
  $metrics = makeTextModelMetrics();
  $wire = $metrics->toArray();
  expect(TextMetrics::fromArray($wire))->toEqual($metrics)->and($wire)->toBe([
    'advance' => 24.5, 'baseline' => 10.0, 'lineBounds' => ['x' => 0.0, 'y' => 0.0, 'width' => 24.5, 'height' => 15.0],
    'inkBounds' => ['x' => -1.25, 'y' => -0.5, 'width' => 25.5, 'height' => 12.0],
    'paintBounds' => ['x' => -3.0, 'y' => -2.0, 'width' => 29.0, 'height' => 17.0],
  ]);
  $empty = new TextBounds(0, 0, 0, 0);
  $blank = new TextMetrics(0, 0, $empty, null, $empty);
  expect(TextMetrics::fromArray($blank->toArray()))->toEqual($blank)->and($blank->toArray()['inkBounds'])->toBeNull();
  expect(new TextMetrics(16384, 16384, $empty, $empty, $empty)->advance)->toBe(16384.0);
});

it('strictly rejects malformed metrics including missing nullable ink and numeric coercion', function (array $data) {
  expect(fn() => TextMetrics::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = makeTextModelMetrics()->toArray();
  $cases = [[[]], [[...$base, 'fontHash' => 'not-identity']]];
  foreach (array_keys($base) as $field) {
    $missing = $base; unset($missing[$field]); $cases[] = [$missing];
  }
  foreach (['advance', 'baseline'] as $field) {
    foreach (['12', false, null, [], new stdClass(), -0.01, 16384.01, INF, -INF, NAN] as $value) {
      $cases[] = [array_replace($base, [$field => $value])];
    }
  }
  foreach (['lineBounds', 'inkBounds', 'paintBounds'] as $field) {
    foreach (['rect', false, [], new stdClass(), ['x' => 0], [...$base['lineBounds'], 'width' => '1']] as $value) {
      $cases[] = [array_replace($base, [$field => $value])];
    }
  }
  foreach (['lineBounds', 'paintBounds'] as $field) { $cases[] = [array_replace($base, [$field => null])]; }
  return $cases;
});

it('validates direct advance and baseline construction', function (float $value) {
  $empty = new TextBounds(0, 0, 0, 0);
  expect(fn() => new TextMetrics($value, 0, $empty, null, $empty))->toThrow(InvalidArgumentException::class);
  expect(fn() => new TextMetrics(0, $value, $empty, null, $empty))->toThrow(InvalidArgumentException::class);
})->with([-1.0, 16384.001, INF, -INF, NAN]);

it('keeps measurements immutable and accepts opaque bounded wire identities', function () {
  $metrics = makeTextModelMetrics();
  foreach (['text-session-1', str_repeat('a', 256), str_repeat("\u{00E9}", 128), "opaque:\u{200D}"] as $id) {
    $measurement = new TextMeasurement($id, $metrics);
    expect($measurement->layoutId)->toBe($id)->and($measurement->metrics)->toBe($metrics);
    expect(fn() => new ReflectionProperty($measurement, 'layoutId')->setValue($measurement, 'replacement'))->toThrow(Error::class);
  }
});

it('refuses malformed or oversized measurement identities using the shared ID contract', function (string $id) {
  expect(fn() => new TextMeasurement($id, makeTextModelMetrics()))->toThrow(InvalidArgumentException::class);
})->with(nameTextModelCases(['', str_repeat('a', 257), str_repeat("\u{00E9}", 129), "text\n1", "text\0", "text\x7F", "text\u{0085}", "\xFF"]));
