<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;

it('rejects malformed or nonfinite colour values and transforms that need substantive clipping', function () {
  $identity = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
  foreach ([[], [[1, 0, 0]], [[1, 0], [0, 1, 0], [0, 0, 1]],
    [[1, '0', 0], [0, 1, 0], [0, 0, 1]], [[NAN, 0, 0], [0, 1, 0], [0, 0, 1]],
    [[INF, 0, 0], [0, 1, 0], [0, 0, 1]], [[2, 0, 0], [0, 1, 0], [0, 0, 1]],
    [[-1, 0, 0], [0, 1, 0], [0, 0, 1]], [1 => [1, 0, 0], 2 => [0, 1, 0], 3 => [0, 0, 1]]] as $matrix) {
    expect(fn() => new CanvasColorTransform($matrix, [0, 0, 0]))->toThrow(InvalidArgumentException::class);
  }
  foreach ([[], [0, 0], [0, 0, 0, 0], [false, 0, 0], [0, '0', 0], [0, 0, -INF],
    [-0.1, 0, 0], [0, 0, 0.1]] as $offset) {
    expect(fn() => new CanvasColorTransform($identity, $offset))->toThrow(InvalidArgumentException::class);
  }
  // The rejected clamp(2*x-0.5) would turn a composed quarter-white surface black,
  // but baking it into white and black drawings first would leave that surface quarter-white.
  expect(fn() => new CanvasColorTransform([[2, 0, 0], [0, 2, 0], [0, 0, 2]], [-0.5, -0.5, -0.5]))
    ->toThrow(InvalidArgumentException::class, 'entire RGB');
});

it('detaches reference aliases and keeps output data immutable', function () {
  $coefficient = 1; $bias = 0;
  $row = [&$coefficient, 0, 0]; $matrix = [&$row, [0, 1, 0], [0, 0, 1]];
  $transform = new CanvasColorTransform($matrix, [&$bias, 0, 0]);
  $coefficient = 5; $bias = 1; $row = [0, 0, 0];
  $wire = $transform->toArray(); $wire['matrix'][0][0] = -1;
  expect($transform->transformRgb(0.2, 0.4, 0.6))->toBe([0.2, 0.4, 0.6])
    ->and($transform->toArray()['offset'])->toBe([0.0, 0.0, 0.0]);
});

it('preserves every cube corner and source-over for translucent overlapping colours', function () {
  $treatments = [
    new CanvasColorTransform([[1, 0, 0], [0, 1, 0], [0, 0, 1]], [0, 0, 0]),
    new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]),
    new CanvasColorTransform([[0.2126, 0.7152, 0.0722], [0.2126, 0.7152, 0.0722], [0.2126, 0.7152, 0.0722]], [0, 0, 0]),
    new CanvasColorTransform([[0.25, -0.25, 0], [0, 0.5, 0], [0, 0, -0.75]], [0.25, 0.1, 0.8]),
  ];
  foreach ($treatments as $transform) {
    foreach ([0.0, 1.0] as $red) { foreach ([0.0, 1.0] as $green) { foreach ([0.0, 1.0] as $blue) {
      foreach ($transform->transformRgb($red, $green, $blue) as $channel) { expect($channel)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1); }
    } } }
    foreach ([0.0, 0.25, 1.0] as $sourceAlpha) { foreach ([0.0, 0.6, 1.0] as $destinationAlpha) {
      $source = [0.8, 0.1, 0.45]; $destination = [0.2, 0.9, 0.3];
      $alpha = $sourceAlpha + $destinationAlpha * (1 - $sourceAlpha);
      if ($alpha === 0.0) { continue; } // Zero-alpha colour is immaterial and stays zero-alpha.
      $blend = static fn(array $s, array $d): array => array_map(
        static fn(float $a, float $b): float => ($a * $sourceAlpha + $b * $destinationAlpha * (1 - $sourceAlpha)) / $alpha, $s, $d);
      $expected = $transform->transformRgb(...$blend($source, $destination));
      $actual = $blend($transform->transformRgb(...$source), $transform->transformRgb(...$destination));
      foreach ($expected as $index => $component) { expect($actual[$index])->toEqualWithDelta($component, 1.0e-12); }
    } }
  }
});

it('applies the parent after the child and refuses invalid RGB input', function () {
  $invert = new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]);
  $dim = new CanvasColorTransform([[0.5, 0, 0], [0, 0.5, 0], [0, 0, 0.5]], [0, 0, 0]);
  expect($dim->transformRgb(...$invert->transformRgb(0.0, 0.5, 1.0)))->toBe([0.5, 0.25, 0.0])
    ->and($invert->transformRgb(...$dim->transformRgb(0.0, 0.5, 1.0)))->toBe([1.0, 0.75, 0.5]);
  foreach ([[NAN, 0.0, 0.0], [0.0, INF, 0.0], [0.0, -0.1, 0.0], [0.0, 0.0, 1.1]] as $rgb) {
    expect(fn() => $invert->transformRgb(...$rgb))->toThrow(InvalidArgumentException::class);
  }
});
