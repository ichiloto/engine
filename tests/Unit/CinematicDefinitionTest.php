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
    ->and($schema['finalizerCommandTypes'])->not->toContain('checkpoint')
    ->and($schema['finalizerCommandShapes']['set_switch']['required']['value'])->toBe('bool')
    ->and($schema['unsafeAuthoredSkipCommandTypes'])->toContain('start_battle', 'give_item', 'knowledge')
    ->and($schema['authoredSkipCommonEventPolicy'])->toBe('reject')
    ->and($schema['summonDefinitionFields'])->toContain('id', 'playback', 'effectTiming', 'availability')
    ->and($schema['summonTimelineFields'])->toBe(['formatVersion', 'fps', 'lengthFrames', 'tracks', 'cues', 'editor'])
    ->and($schema['summonPlaybackConfigFields'])->toBe(['defaultSpeed', 'allowSkip', 'loopPreview'])
    ->and($schema['summonPlaybackFields'])->toContain('currentFrame', 'isPaused', 'isCompleted', 'isLooping');
});

it('rejects authored skipping when any reachable command is irreversible', function (array $commands, string $needle) {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'unsafe-skip-tree',
    'name' => 'Unsafe Skip Tree',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'set_switch', 'name' => 'skip_done', 'value' => true]],
  ], $commands))->toThrow(InvalidArgumentException::class, $needle);
})->with([
  'direct battle' => [[['type' => 'start_battle', 'troop' => 'Dragon']], 'start_battle'],
  'sequence item grant' => [[['type' => 'sequence', 'commands' => [['type' => 'give_item', 'item' => 'Potion']]]], 'give_item'],
  'parallel gold grant' => [[['type' => 'parallel', 'lanes' => [[['type' => 'give_gold', 'amount' => 1]]]]], 'give_gold'],
  'branch quest acceptance' => [[['type' => 'branch', 'then' => [['type' => 'accept_quest', 'quest' => 'test']]]], 'accept_quest'],
  'choice recovery' => [[['type' => 'choice', 'options' => [['text' => 'Proceed', 'then' => [['type' => 'recover_party']]]]]], 'recover_party'],
  'cancel knowledge write' => [[['type' => 'choice', 'options' => [], 'cancel' => [['type' => 'knowledge', 'operation' => 'discover']]]], 'knowledge'],
]);

it('conservatively rejects Common Events inside authored-skippable cinematics', function () {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'common-event-skip',
    'name' => 'Common Event Skip',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'set_switch', 'name' => 'skip_done', 'value' => true]],
  ], [[
    'type' => 'sequence',
    'commands' => [['type' => 'common_event', 'id' => 'shared-cleanup']],
  ]]))->toThrow(InvalidArgumentException::class, 'Common Event "shared-cleanup"');
});

it('validates finalizer payload shapes instead of accepting type-only commands', function (array $finalizer, string $needle) {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'bad-finalizer-shape',
    'name' => 'Bad Finalizer Shape',
    'skip' => ['policy' => 'authored'],
    'finalizer' => $finalizer,
  ], []))->toThrow(InvalidArgumentException::class, $needle);
})->with([
  'switch value required' => [[['type' => 'set_switch', 'name' => 'done']], 'explicit boolean value'],
  'variable add forbidden' => [[['type' => 'set_variable', 'name' => 'count', 'value' => 1, 'op' => 'add']], 'only allow the "set" operation'],
  'event identity stable' => [[['type' => 'record_event', 'name' => 'bad event']], 'stable event identity'],
  'integral movement' => [[['type' => 'move_player', 'x' => 1.5, 'y' => 2]], 'integral x coordinate'],
  'transfer map required' => [[['type' => 'transfer', 'map' => '', 'x' => 1, 'y' => 2]], 'Map must be a non-empty string'],
  'camera operation restricted' => [[['type' => 'camera', 'operation' => 'pan']], 'only allow attach or reset'],
  'actor identity required' => [[['type' => 'remove_actor']], 'non-empty actor ID'],
  'presentation payload forbidden' => [[['type' => 'clear_presentation', 'unsafe' => true]], 'Unsafe payload field'],
  'music policy complete' => [[['type' => 'cinematic_music', 'track' => 'music/test', 'loop' => true]], 'explicit completion policy'],
  'checkpoint removed' => [[['type' => 'checkpoint', 'name' => 'done']], 'uses unsafe type "checkpoint"'],
]);

it('accepts every deterministic finalizer type with a complete valid shape', function () {
  $definition = CinematicDefinition::fromArrays([
    'id' => 'complete-finalizer',
    'name' => 'Complete Finalizer',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [
      ['type' => 'set_switch', 'name' => 'complete', 'value' => true],
      ['type' => 'set_variable', 'name' => 'outcome', 'value' => 'safe', 'op' => 'set'],
      ['type' => 'record_event', 'name' => 'cinematic:complete-finalizer:resolved'],
      ['type' => 'move_player', 'x' => 2, 'y' => 3],
      ['type' => 'transfer', 'map' => 'maps/destination', 'x' => 2, 'y' => 3, 'sprite' => ['@']],
      ['type' => 'camera', 'operation' => 'reset'],
      ['type' => 'remove_actor', 'actorId' => 'temporary-actor'],
      ['type' => 'clear_presentation'],
      [
        'type' => 'cinematic_music',
        'track' => 'music/field',
        'loop' => true,
        'fadeIn' => 0.0,
        'fadeOut' => 0.2,
        'completionBehavior' => 'continue',
      ],
    ],
  ], []);

  expect(array_column($definition->finalizer, 'type'))->toBe(CinematicCommandSchema::FINALIZER_COMMAND_TYPES);
});

it('fails closed on malformed cinematic list fields, versions and route steps', function (array $data, array $script, string $needle) {
  expect(fn() => CinematicDefinition::fromArrays([
    'id' => 'strict-hydration',
    'name' => 'Strict Hydration',
    ...$data,
  ], $script))->toThrow(InvalidArgumentException::class, $needle);
})->with([
  'cast must be list' => [['cast' => ['actor' => ['id' => 'actor', 'sprite' => '@']]], [], 'field "cast" must be a list'],
  'cast entries keyed' => [['cast' => ['actor']], [], 'cast entry 1 must be a keyed array'],
  'finalizer must be list' => [['finalizer' => ['command' => ['type' => 'clear_presentation']]], [], 'field "finalizer" must be a list'],
  'finalizer entries keyed' => [['finalizer' => ['not-a-command']], [], 'Finalizer entries must be keyed command arrays'],
  'checkpoints stable' => [['checkpoints' => ['bad checkpoint']], [], 'non-empty stable string'],
  'checkpoints unique' => [['checkpoints' => ['same', 'same']], [], 'duplicate checkpoint "same"'],
  'version positive integer' => [['version' => '2'], [], 'version must be a positive integer'],
  'script commands list' => [[], ['commands' => ['first' => ['type' => 'wait']]], 'script.commands must be a command list'],
  'script entries keyed' => [[], ['not-a-command'], 'Each command must be a keyed array'],
  'route steps keyed' => [[], [['type' => 'move_route', 'steps' => ['right']]], 'Movement-route step must be a keyed array'],
]);

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
