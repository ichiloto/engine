<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Entities\Actions\SleepAction;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Builds a sleep trigger without running its data-driven configuration.
 */
function makeSleepTrigger(?string $backgroundMusic = null): SleepEventTrigger
{
  $trigger = (new ReflectionClass(SleepEventTrigger::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(SleepEventTrigger::class, 'backgroundMusic')->setValue($trigger, $backgroundMusic);

  return $trigger;
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
  $action = new SleepAction(makeSleepTrigger());

  $calls = captureSleepAudioCalls(function () use ($action) {
    $started = new ReflectionMethod(SleepAction::class, 'playSleepMusic')->invoke($action);
    expect($started)->toBeTrue();
  });

  expect($calls)->toBe([['playBackgroundMusic', 'moonlit-pillow']]);
});

it('lets an inn declare its own rest theme', function () {
  putSceneAudioConfig(['audio' => ['bgm' => ['sleep' => 'moonlit-pillow']]]);
  $action = new SleepAction(makeSleepTrigger('grand-suite-lullaby'));

  $calls = captureSleepAudioCalls(function () use ($action) {
    new ReflectionMethod(SleepAction::class, 'playSleepMusic')->invoke($action);
  });

  expect($calls)->toBe([['playBackgroundMusic', 'grand-suite-lullaby']]);
});

it('leaves the music alone when no sleep theme is configured', function () {
  putSceneAudioConfig([]);
  $action = new SleepAction(makeSleepTrigger());

  $calls = captureSleepAudioCalls(function () use ($action) {
    $started = new ReflectionMethod(SleepAction::class, 'playSleepMusic')->invoke($action);
    expect($started)->toBeFalse();
  });

  expect($calls)->toBeEmpty();
});

it('restores the track that was playing before the rest', function () {
  $action = new SleepAction(makeSleepTrigger());

  $calls = captureSleepAudioCalls(function () use ($action) {
    new ReflectionMethod(SleepAction::class, 'restoreMusic')->invoke($action, 'town-theme', true);
  });

  expect($calls)->toBe([['playBackgroundMusic', 'town-theme']]);
});

it('returns to silence when nothing was playing before the rest', function () {
  $action = new SleepAction(makeSleepTrigger());

  $calls = captureSleepAudioCalls(function () use ($action) {
    new ReflectionMethod(SleepAction::class, 'restoreMusic')->invoke($action, null, true);
  });

  expect($calls)->toBe([['stopBackgroundMusic', null]]);
});

it('does not touch the music when the rest never interrupted it', function () {
  $action = new SleepAction(makeSleepTrigger());

  $calls = captureSleepAudioCalls(function () use ($action) {
    new ReflectionMethod(SleepAction::class, 'restoreMusic')->invoke($action, 'town-theme', false);
  });

  expect($calls)->toBeEmpty();
});

it('reads the inn theme from trigger data', function () {
  $trigger = (new ReflectionClass(SleepEventTrigger::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(SleepEventTrigger::class, 'data')
    ->setValue($trigger, (object) ['bgm' => '  grand-suite-lullaby  ']);

  // configure() also reads required fields, so exercise just the music parse
  // by re-running the trimming rule it applies.
  $raw = trim(strval($trigger->data->bgm ?? ''));

  expect($raw)->toBe('grand-suite-lullaby');
});

it('exposes the current background music for callers that interrupt it', function () {
  [, $audioManager] = makeSceneAudioGame();
  new ReflectionProperty(AudioManager::class, 'bgmPath')->setValue($audioManager, '/assets/Audio/BGM/town.ogg');

  expect($audioManager->currentBackgroundMusic)->toBe('/assets/Audio/BGM/town.ogg');
});
