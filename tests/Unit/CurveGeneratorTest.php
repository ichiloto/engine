<?php

use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\ParameterCurveGenerator;

it('clamps parameter curve lookups to the highest generated level', function () {
  $generator = new ParameterCurveGenerator(level: 100, baseValue: 100, extraGrowth: 50, flatIncrement: 2);

  expect($generator->getValue(100))->toBeGreaterThan(0)
    ->and($generator->getValue(101))->toBe($generator->getValue(100))
    ->and($generator->getValue(999))->toBe($generator->getValue(100));
});

it('clamps experience curve lookups to the highest generated level', function () {
  $generator = new ExperienceCurveGenerator();

  expect($generator->getValue(100))->toBeGreaterThan(0)
    ->and($generator->getValue(101))->toBe($generator->getValue(100))
    ->and($generator->getValue(999))->toBe($generator->getValue(100));
});
