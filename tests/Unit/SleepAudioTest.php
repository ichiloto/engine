<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
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

it('decodes shared inn descriptors from authored arrays, decoded objects and real Sleep triggers', function (array $descriptor) {
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'cost' => 5,
    'spawnPoint' => ['x' => 4, 'y' => 2], 'spawnSprite' => ['v'], 'bgm' => 'rest-theme',
    'presentation' => $descriptor];
  $expected = ['treatment' => $descriptor['treatment'], 'leaders' => $descriptor['leaders'] ?? [],
    'parties' => $descriptor['parties'] ?? []];
  expect(InnOffer::fromData($data)->presentation->toArray())->toBe($expected);

  $trigger = EventTriggerFactory::create(['class' => SleepEventTrigger::class,
    'area' => ['cells' => [[1, 1]]], 'data' => $data]);
  $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
  expect($trigger)->toBeInstanceOf(SleepEventTrigger::class)
    ->and($trigger->data->presentation)->toBeInstanceOf(stdClass::class);
  if ($descriptor['treatment'] === 'leader') {
    expect($trigger->data->presentation->leaders)->toBeInstanceOf(stdClass::class);
  } else {
    expect($trigger->data->presentation->parties[0])->toBeInstanceOf(stdClass::class)
      ->and($trigger->data->presentation->parties[0]->actors)->toBeArray();
  }
  foreach ([$trigger->offer, InnOffer::fromData($decoded),
    InnOffer::fromData([...$data, 'presentation' => $decoded->presentation])] as $offer) {
    expect($offer->presentation)->toBeInstanceOf(PartyStageSelection::class)
      ->and($offer->presentation->toArray())->toBe($expected)
      ->and($offer->cost)->toBe(5)->and($offer->confirmDialogue->text)->toBe('Synthetic rest?')
      ->and([$offer->spawnPoint->x, $offer->spawnPoint->y])->toEqual([4, 2])
      ->and($offer->spawnSprite)->toBe(['v'])->and($offer->backgroundMusic)->toBe('rest-theme');
  }
})->with([
  'leader bindings' => [['treatment' => 'leader', 'leaders' => ['alpha' => 'alpha-rest', 'beta' => 'beta-rest']]],
  'nested party bindings' => [['treatment' => 'party', 'parties' => [
    ['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest'],
    ['actors' => ['alpha'], 'timeline' => 'solo-rest'],
  ]]],
]);

it('preserves already typed inn stage selections in array and object data', function (string $treatment) {
  $selection = $treatment === 'leader'
    ? new PartyStageSelection($treatment, ['alpha' => 'alpha-rest'])
    : new PartyStageSelection($treatment, parties: [['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest']]);
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'presentation' => $selection];
  expect(InnOffer::fromData($data)->presentation)->toBe($selection)
    ->and(InnOffer::fromData((object) $data)->presentation)->toBe($selection);
})->with(['leader', 'party']);

it('refuses malformed inn descriptors without turning decoded objects into authored lists', function (mixed $descriptor) {
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'spawnPoint' => ['x' => 4, 'y' => 2],
    'spawnSprite' => ['v'], 'presentation' => $descriptor];
  $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
  expect(fn() => InnOffer::fromData($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => InnOffer::fromData($decoded))->toThrow(InvalidArgumentException::class)
    ->and(fn() => EventTriggerFactory::create(['class' => SleepEventTrigger::class,
      'area' => ['cells' => [[1, 1]]], 'data' => $data]))->toThrow(InvalidArgumentException::class);
})->with([
  'empty decoded record' => [(object) []],
  'unknown descriptor key' => [['treatment' => 'leader', 'guests' => []]],
  'object timeline identity' => [['treatment' => 'leader', 'leaders' => ['alpha' => (object) []]]],
  'object instead of party list' => [['treatment' => 'party', 'parties' => (object) [
    ['actors' => ['alpha'], 'timeline' => 'rest'],
  ]]],
  'object instead of actor list' => [['treatment' => 'party', 'parties' => [
    ['actors' => (object) ['alpha'], 'timeline' => 'rest'],
  ]]],
  'unknown nested binding key' => [['treatment' => 'party', 'parties' => [
    ['actors' => ['alpha'], 'timeline' => 'rest', 'other' => true],
  ]]],
  'party with leader fallback' => [['treatment' => 'party', 'leaders' => ['alpha' => 'rest']]],
  'repeated guest identity' => [['treatment' => 'party', 'parties' => [
    ['actors' => ['alpha', 'alpha'], 'timeline' => 'rest'],
  ]]],
]);

it('does not admit arbitrary runtime objects as decoded inn descriptor records', function () {
  $descriptor = new class {
    public string $treatment = 'leader';
    public array $leaders = ['alpha' => 'rest'];
  };
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'presentation' => $descriptor];
  expect(fn() => InnOffer::fromData($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => InnOffer::fromData((object) $data))->toThrow(InvalidArgumentException::class);
});
