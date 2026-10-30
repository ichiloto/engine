<?php

use Ichiloto\Engine\Battle\Presentation\{BattleGround, BattleGroundBand, BattleGroundScale};

it('projects measured guides at every depth and round trips the authored reference', function () {
  $source = ['horizonY' => 100, 'referenceY' => 600, 'pixelsPerUnit' => 100, 'vanishingX' => 640];
  $scale = BattleGroundScale::fromArray($source);
  expect($scale->getProjectedHeight(600, 1.8))->toBe(180.0)
    ->and($scale->getProjectedHeight(350, 1.8))->toBe(90.0)
    ->and($scale->getProjectedHeight(200, .75))->toBe(15.0)
    ->and(BattleGroundScale::fromArray($scale->toArray()))->toEqual($scale);
  $band = new BattleGroundBand(200, 700, scaleReference: $scale);
  $line = $band->getDepthScaleLine();
  foreach ([200, 300, 350, 500, 600, 700] as $row) {
    expect($band->getDepthScale($row))->toEqualWithDelta(($row - 100) / 500, 1e-9)
      ->and($line['slope'] * $row + $line['intercept'])->toEqualWithDelta($band->getDepthScale($row), 1e-9);
  }
  expect($band->getDepthScale(50))->toBe($band->getDepthScale(200))
    ->and($band->getDepthScale(800))->toBe($band->getDepthScale(700))
    ->and($band->toArray())->toBe(['top' => 200.0, 'bottom' => 700.0, 'scaleReference' => $scale->toArray()]);
});

it('inherits one explicit scale model and lets an arena deliberately replace it', function () {
  $scale = new BattleGroundScale(100, 600, 100);
  $legacy = new BattleGround(200, 700, .8);
  $measured = new BattleGround(scaleReference: $scale);
  expect($measured->getOver($legacy))->toEqual(new BattleGround(200, 700, scaleReference: $scale))
    ->and(new BattleGround(top: 250)->getOver(new BattleGround(200, 700, scaleReference: $scale)))
      ->toEqual(new BattleGround(250, 700, scaleReference: $scale))
    ->and(new BattleGround(perspective: .7)->getOver(new BattleGround(200, 700, scaleReference: $scale)))
      ->toEqual(new BattleGround(200, 700, .7))
    ->and(BattleGround::fromArray($measured->toArray()))->toEqual($measured)
    ->and(fn() => new BattleGround(perspective: 1, scaleReference: $scale))->toThrow(InvalidArgumentException::class);
});

it('refuses malformed or physically invalid measured projection before drawing', function () {
  $valid = ['horizonY' => 100, 'referenceY' => 600, 'pixelsPerUnit' => 100];
  foreach ([null, [], [...$valid, 'pixelsPerUnit' => '100'], [...$valid, 'pixelsPerUnit' => 0],
    [...$valid, 'pixelsPerUnit' => INF], [...$valid, 'horizonY' => NAN], [...$valid, 'horizonY' => 600],
    [...$valid, 'referenceY' => 0], [...$valid, 'vanishingX' => null], [...$valid, 'unknown' => 1]] as $invalid) {
    expect(fn() => BattleGroundScale::fromArray($invalid))->toThrow(InvalidArgumentException::class);
  }
  $scale = BattleGroundScale::fromArray($valid);
  expect(fn() => BattleGround::fromArray(['scaleReference' => null]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleGroundBand(100, 700, scaleReference: $scale))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleGroundBand(101, 700, scaleReference: new BattleGroundScale(100, 101, 100)))
      ->toThrow(InvalidArgumentException::class)
    ->and(fn() => $scale->getProjectedHeight(600, 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $scale->getProjectedHeight(600, PHP_FLOAT_MAX))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleGroundScale(0, 1e-320, 100))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $scale->getDepthScale(100))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleGroundBand(200, 700, scaleReference: $scale)->getDepthScale(NAN))
      ->toThrow(InvalidArgumentException::class);
});
