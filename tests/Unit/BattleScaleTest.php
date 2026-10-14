<?php

use Ichiloto\Engine\Battle\Presentation\BattleScale;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;

it('preserves a body unit when current artwork resolution changes without pinning image dimensions', function () {
  $profile = new BattlerScale(1, .75);
  $small = new BattlerArtwork('small.png', 100, 200, 50, 180);
  $large = new BattlerArtwork('large.png', 200, 400, 100, 360);
  expect($profile->getPixelScale(150, $small))->toBe(1.0)
    ->and($profile->getPixelScale(150, $large))->toBe(.5);
});

it('validates reference scale registrations and refuses competing size authorities', function () {
  $art = new BattlerArtwork('hero.png', 100, 200, 50, 180);
  $scale = new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, .75)]);
  expect(fn() => new BattlePresentationCatalog([], ['Hero' => $art], [], scale: $scale))->not->toThrow(Throwable::class)
    ->and(fn() => new BattlePresentationCatalog([], ['Other' => $art], [], scale: $scale))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattlePresentationCatalog([], ['Hero' => $art], [],
      actorPoses: ['Hero' => new BattlePoseSet([], 100)], scale: $scale))
    ->toThrow(InvalidArgumentException::class);
});

it('refuses invalid body units and missing or non-unit reference actors', function () {
  foreach ([0.0, -1.0, INF, NAN] as $value) {
    expect(fn() => new BattlerScale($value, .75))->toThrow(InvalidArgumentException::class)
      ->and(fn() => new BattlerScale(1, $value))->toThrow(InvalidArgumentException::class)
      ->and(fn() => new BattleScale('Hero', $value, ['Hero' => new BattlerScale(1, .75)]))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new BattleScale('Hero', 150, []))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleScale('Hero', 150, ['Hero' => new BattlerScale(.5, .75)]))
    ->toThrow(InvalidArgumentException::class);
});

it('requires reference calibration for pose-only registrations on either side', function (bool $party) {
  $art = new BattlerArtwork('hero.png', 100, 200, 50, 180);
  $poses = ['Other' => new BattlePoseSet(['idle' => new BattlerPose('other.png')])];
  $scale = new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, .75)]);
  expect(fn() => new BattlePresentationCatalog([], ['Hero' => $art], [],
    actorPoses: $party ? $poses : [], enemyPoses: $party ? [] : $poses, scale: $scale))
    ->toThrow(InvalidArgumentException::class, 'Reference battle scale requires a body profile for Other.');
})->with([false, true]);

it('refuses a competing pose-only display width even when a body profile exists', function (bool $party) {
  $art = new BattlerArtwork('hero.png', 100, 200, 50, 180);
  $profile = new BattlerScale(.5, .75);
  $scale = new BattleScale('Hero', 150,
    ['Hero' => new BattlerScale(1, .75), ...($party ? ['Other' => $profile] : [])],
    $party ? [] : ['Other' => $profile]);
  $poses = ['Other' => new BattlePoseSet(['idle' => new BattlerPose('other.png')], displayWidth: 100)];
  expect(fn() => new BattlePresentationCatalog([], ['Hero' => $art], [],
    actorPoses: $party ? $poses : [], enemyPoses: $party ? [] : $poses, scale: $scale))
    ->toThrow(InvalidArgumentException::class, 'Other: reference battle scale cannot also use legacy displayWidth.');
})->with([false, true]);

it('retains calibrated pose-only registrations and projects without reference scale', function (bool $party) {
  $art = new BattlerArtwork('hero.png', 100, 200, 50, 180);
  $profile = new BattlerScale(.5, .75);
  $scale = new BattleScale('Hero', 150,
    ['Hero' => new BattlerScale(1, .75), ...($party ? ['Other' => $profile] : [])],
    $party ? [] : ['Other' => $profile]);
  foreach ([null, $scale] as $registration) {
    $poses = ['Other' => new BattlePoseSet(['idle' => new BattlerPose('other.png')],
      displayWidth: $registration === null ? 100 : null)];
    $catalog = new BattlePresentationCatalog([], ['Hero' => $art], [],
      actorPoses: $party ? $poses : [], enemyPoses: $party ? [] : $poses, scale: $registration);
    expect(($party ? $catalog->actorPoses : $catalog->enemyPoses)['Other'])->toBe($poses['Other'])
      ->and($catalog->scale)->toBe($registration);
  }
})->with([false, true]);
