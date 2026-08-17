<?php

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicLibrary;
function cinematicFixtureRoot(): string
{
  return dirname(__DIR__) . '/Fixtures/Projects/CinematicAcceptance/assets/Cutscenes/Cinematics';
}

it('discovers and hydrates original folder-based cinematic assets', function () {
  $library = new CinematicLibrary(cinematicFixtureRoot());
  $definition = $library->load('sky-caravan');

  expect($library->ids())->toBe(['sky-caravan'])
    ->and($definition)->toBeInstanceOf(CinematicDefinition::class)
    ->and($definition->id)->toBe('sky-caravan')
    ->and($definition->name)->toBe('The Sky Caravan')
    ->and($definition->startMap)->toBe('cinematic/skyfield-night')
    ->and($definition->cast)->toHaveCount(3)
    ->and($definition->skipPolicy)->toBe('authored')
    ->and($definition->commands)->not->toBeEmpty()
    ->and($definition->extra['testFixture'])->toBe(['mapWidth' => 100, 'mapHeight' => 60]);
});

it('keeps stable identity independent from its display name', function () {
  $definition = CinematicDefinition::fromArrays([
    'id' => 'stable-opening',
    'name' => 'First Display Name',
  ], []);
  $renamed = CinematicDefinition::fromArrays([
    'id' => 'stable-opening',
    'name' => 'A Better Display Name',
  ], []);

  expect($renamed->id)->toBe($definition->id)
    ->and($renamed->name)->not->toBe($definition->name);
});

it('exports one authoritative runtime and authoring vocabulary', function () {
  $schema = CinematicCommandSchema::export();

  expect($schema['commandTypes'])->toContain('parallel', 'camera', 'field_animation', 'cinematic_music')
    ->and($schema['nestedBlockShapes']['parallel']['lane'])->toBe(['id', 'commands'])
    ->and($schema['subjectKinds'])->toContain('player', 'npc', 'staged_actor', 'marker')
    ->and($schema['cameraOperations'])->toContain('detach', 'pan', 'track', 'route', 'shake', 'restore')
    ->and($schema['cinematicDefinitionFields'])->toContain('id', 'name', 'cast', 'skip', 'finalizer')
    ->and($schema['skipPolicies'])->toBe(['forbidden', 'authored'])
    ->and($schema['summonDefinitionFields'])->toContain('id', 'playback', 'effectTiming', 'availability')
    ->and($schema['summonTimelineFields'])->toBe(['formatVersion', 'fps', 'lengthFrames', 'tracks', 'cues', 'editor'])
    ->and($schema['summonPlaybackConfigFields'])->toBe(['defaultSpeed', 'allowSkip', 'loopPreview'])
    ->and($schema['summonPlaybackFields'])->toContain('currentFrame', 'isPaused', 'isCompleted', 'isLooping');
});

it('reports malformed nested blocks with stable cinematic command paths', function () {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'broken-parallel',
    'name' => 'Broken Parallel',
  ], [[
    'type' => 'parallel',
    'lanes' => [[
      'id' => 'camera-lane',
      'commands' => [[
        'type' => 'sequence',
        'commands' => 'not-a-list',
      ]],
    ]],
  ]]))->toThrow(
    InvalidArgumentException::class,
    'Cinematic "broken-parallel" is invalid at command path "script[1]/parallel[camera-lane][1]/sequence"',
  );
});

it('rejects duplicate cast and unsafe or absent authored finalizers', function () {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'duplicate-cast',
    'name' => 'Duplicate Cast',
    'cast' => [
      ['id' => 'runner', 'sprite' => '@'],
      ['id' => 'runner', 'sprite' => '@'],
    ],
  ], []))->toThrow(InvalidArgumentException::class, 'duplicate cast id "runner"');

  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'missing-finalizer',
    'name' => 'Missing Finalizer',
    'skip' => ['policy' => 'authored'],
  ], []))->toThrow(InvalidArgumentException::class, 'requires an authored finalizer');

  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'unsafe-finalizer',
    'name' => 'Unsafe Finalizer',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'give_gold', 'amount' => 10]],
  ], []))->toThrow(InvalidArgumentException::class, 'uses unsafe type "give_gold"');
});

it('rejects empty parallel blocks and malformed cinematic fields before runtime', function () {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'empty-parallel',
    'name' => 'Empty Parallel',
  ], [[
    'type' => 'sequence',
    'commands' => [[
      'type' => 'parallel',
      'lanes' => [],
    ]],
  ]]))->toThrow(InvalidArgumentException::class, 'must not be empty');

  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'invalid-camera-duration',
    'name' => 'Invalid Camera Duration',
  ], [[
    'type' => 'camera',
    'operation' => 'pan',
    'target' => ['kind' => 'position', 'x' => 4, 'y' => 5],
    'seconds' => -1,
  ]]))->toThrow(InvalidArgumentException::class, 'greater than zero');

  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'invalid-staged-cast',
    'name' => 'Invalid Staged Cast',
    'cast' => [['id' => 'actor-without-art']],
  ], []))->toThrow(InvalidArgumentException::class, 'requires a sprite or asset reference');
});
