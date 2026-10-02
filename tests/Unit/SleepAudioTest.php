<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Inn\InnOffer;
use Ichiloto\Engine\Inn\InnStay;
use Ichiloto\Engine\Messaging\Dialogue\ConfirmDialogue;

/**
 * Builds the stay an inn offers, as the sleep trigger and the inn command do.
 */
function makeInnStay(?string $backgroundMusic = null): InnStay
{
  return new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest here?'), backgroundMusic: $backgroundMusic));
}

/**
 * Runs the given callback with a recording audio manager installed, returning
 * the calls it captured.
 *
 * @return array<int, array{string, mixed}>
 */
function captureSleepAudioCalls(callable $callback): array
{
  [, $audioManager] = makeSceneAudioGame();
  $instanceProperty = new ReflectionProperty(AudioManager::class, 'instance');
  $instanceProperty->setValue(null, $audioManager);

  try {
    $callback();

    return $audioManager->calls;
  } finally {
    $instanceProperty->setValue(null, null);
  }
}

afterEach(function () {
  putSceneAudioConfig([]);
});

it('plays the configured sleep theme while the party rests', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['sleep' => 'moonlit-pillow']]]);
  $stay = makeInnStay();

  $calls = captureSleepAudioCalls(function () use ($stay) {
    $started = new ReflectionMethod(InnStay::class, 'playSleepMusic')->invoke($stay);
    expect($started)->toBeTrue();
  });

  expect($calls)->toBe([['playBackgroundMusic', 'moonlit-pillow']]);
});

it('lets an inn declare its own rest theme', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['sleep' => 'moonlit-pillow']]]);
  $stay = makeInnStay('grand-suite-lullaby');

  $calls = captureSleepAudioCalls(function () use ($stay) {
    new ReflectionMethod(InnStay::class, 'playSleepMusic')->invoke($stay);
  });

  expect($calls)->toBe([['playBackgroundMusic', 'grand-suite-lullaby']]);
});

it('leaves the music alone when no sleep theme is configured', function () {
  putSceneAudioConfig([]);
  $stay = makeInnStay();

  $calls = captureSleepAudioCalls(function () use ($stay) {
    $started = new ReflectionMethod(InnStay::class, 'playSleepMusic')->invoke($stay);
    expect($started)->toBeFalse();
  });

  expect($calls)->toBeEmpty();
});

it('restores the track that was playing before the rest', function () {
  $stay = makeInnStay();

  $calls = captureSleepAudioCalls(function () use ($stay) {
    new ReflectionMethod(InnStay::class, 'restoreMusic')->invoke($stay, 'town-theme', true);
  });

  expect($calls)->toBe([['playBackgroundMusic', 'town-theme']]);
});

it('returns to silence when nothing was playing before the rest', function () {
  $stay = makeInnStay();

  $calls = captureSleepAudioCalls(function () use ($stay) {
    new ReflectionMethod(InnStay::class, 'restoreMusic')->invoke($stay, null, true);
  });

  expect($calls)->toBe([['stopBackgroundMusic', null]]);
});

it('does not touch the music when the rest never interrupted it', function () {
  $stay = makeInnStay();

  $calls = captureSleepAudioCalls(function () use ($stay) {
    new ReflectionMethod(InnStay::class, 'restoreMusic')->invoke($stay, 'town-theme', false);
  });

  expect($calls)->toBeEmpty();
});

it('reads the inn theme from the shared inn data', function (array|object $data) {
  expect(InnOffer::fromData($data)->backgroundMusic)->toBe('grand-suite-lullaby');
})->with([
  'script command array' => [['confirmDialogue' => ['text' => 'Rest here?'], 'bgm' => '  grand-suite-lullaby  ']],
  'decoded trigger data' => [(object) ['confirmDialogue' => (object) ['text' => 'Rest here?'], 'bgm' => 'grand-suite-lullaby']],
]);

it('exposes the current background music for callers that interrupt it', function () {
  [, $audioManager] = makeSceneAudioGame();
  new ReflectionProperty(AudioManager::class, 'bgmPath')->setValue($audioManager, '/assets/Audio/BGM/town.ogg');

  expect($audioManager->currentBackgroundMusic)->toBe('/assets/Audio/BGM/town.ogg');
});
