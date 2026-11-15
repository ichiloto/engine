<?php

declare(strict_types=1);

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFramingDefinition;
use Ichiloto\Engine\Events\Interpreter\EventCommandRequirements;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Events\Interpreter\EventScriptContext;

function requiredPhase(array $subjects = ['selected']): array {
  return ['type' => 'framing', 'subjects' => $subjects, 'commands' => [['type' => 'wait', 'seconds' => .1]]];
}

function wrapRequiredPhase(array $phase, string $kind): array {
  return match ($kind) {
    'root' => $phase,
    'sequence' => ['type' => 'sequence', 'commands' => [$phase]],
    'framing' => ['type' => 'framing', 'subjects' => [['kind' => 'player']], 'commands' => [$phase]],
    'parallel named' => ['type' => 'parallel', 'lanes' => [['id' => 'selected', 'commands' => [$phase]]]],
    'parallel list' => ['type' => 'parallel', 'lanes' => [[$phase]]],
    'branch then' => ['type' => 'branch', 'then' => [$phase]],
    'branch else' => ['type' => 'branch', 'else' => [$phase]],
    'choice option' => ['type' => 'choice', 'options' => [['text' => 'Proceed', 'then' => [$phase]]]],
    'choice cancel' => ['type' => 'choice', 'options' => [], 'cancel' => [$phase]],
    'storybook finish' => ['type' => 'play_storybook', 'storybook' => 'selected', 'onFinish' => [$phase]],
  };
}

dataset('framing owners', ['root', 'sequence', 'framing', 'parallel named', 'parallel list',
  'branch then', 'branch else', 'choice option', 'choice cancel', 'storybook finish']);

it('admits own-script keys and refuses unbound selections at definition hydration through every command block', function (string $block): void {
  $data = ['id' => 'synthetic', 'name' => 'Synthetic', 'presentation' => ['framing' => ['subjects' => [
    'selected' => ['kind' => 'npc', 'id' => 'subject'],
  ]]]];
  $valid = [wrapRequiredPhase(requiredPhase(), $block)];
  expect(CinematicDefinition::fromArrays($data, $valid)->commands)->toBe($valid);
  expect(fn() => CinematicDefinition::fromArrays($data, [wrapRequiredPhase(requiredPhase(['unbound']), $block)]))
    ->toThrow(InvalidArgumentException::class, 'framing subject key is not bound');
  expect(fn() => CinematicDefinition::fromArrays($data,
    [wrapRequiredPhase(requiredPhase(['selected', ['kind' => 'npc', 'id' => 'subject']]), $block)]))
    ->toThrow(InvalidArgumentException::class, 'each field identity once');
})->with('framing owners');

it('refuses nested framing before a plain field script can run', function (string $block): void {
  $commands = [wrapRequiredPhase(requiredPhase([['kind' => 'player']]), $block)];
  expect(fn() => EventCommandRequirements::validate($commands, EventScriptContext::FIELD))
    ->toThrow(InvalidArgumentException::class, 'requires an active field cinematic');
  EventCommandRequirements::validate($commands, EventScriptContext::CINEMATIC);
})->with('framing owners');

it('carries a real call-site context through reusable calls and cycles', function (): void {
  $scripts = [
    'shared' => [['type' => 'sequence', 'commands' => [['type' => 'common_event', 'id' => 'inner']]]],
    'inner' => [requiredPhase([['kind' => 'player']]), ['type' => 'common_event', 'id' => 'shared']],
  ];
  $reads = [];
  $reader = function (string $id) use ($scripts, &$reads): array { $reads[] = $id; return $scripts[$id]; };
  $call = [['type' => 'common_event', 'id' => 'shared']];
  expect(fn() => EventCommandRequirements::validate($call, EventScriptContext::FIELD, $reader))
    ->toThrow(InvalidArgumentException::class, '/common_event:inner[1]');
  $reads = [];
  EventCommandRequirements::validate($call, EventScriptContext::CINEMATIC, $reader);
  expect($reads)->toBe(['shared', 'inner']);
  EventCommandRequirements::validate($scripts['inner'], EventScriptContext::REUSABLE);
});

it('keeps caller-private bindings outside reusable events', function (): void {
  $bindings = new CinematicFramingDefinition(['subjects' => ['selected' => ['kind' => 'player']]]);
  $local = [requiredPhase()];
  EventCommandRequirements::validate($local, EventScriptContext::CINEMATIC, framing: $bindings);
  expect(fn() => EventCommandRequirements::validate([['type' => 'common_event', 'id' => 'selected']],
    EventScriptContext::CINEMATIC, static fn() => $local, $bindings))
    ->toThrow(InvalidArgumentException::class, 'framing subject key is not bound');
  expect(fn() => EventCommandRequirements::validate($local, EventScriptContext::REUSABLE))
    ->toThrow(InvalidArgumentException::class, 'framing subject key is not bound');
});

it('exports runtime availability and exact field identities without a broad Editor picker category', function (): void {
  $schema = CinematicCommandSchema::export(); $framing = $schema['framingRequirements'];
  expect(array_keys($framing['referenceSources']))->toBe(CinematicFramingDefinition::SUBJECT_KINDS)
    ->and($framing)->not->toHaveKey('subjectPicker')
    ->and($framing['referenceSources']['player']['idField'])->toBeNull()
    ->and($framing['referenceSources']['npc']['idField'])->toBe('id')
    ->and($framing['bodyLane'])->toBe(['pathSuffix' => CinematicCommandSchema::FRAMING_BODY_LANE,
      'rootFrame' => CinematicCommandSchema::FRAMING_BODY_LANE])
    ->and($schema['commandRequirements']['cinematic'])->toBe(EventCommandRequirements::CINEMATIC_COMMAND_TYPES)
    ->and(EventExecutionScope::FIELD->allowsBuiltInCommand('framing'))->toBeFalse()
    ->and(EventExecutionScope::FIELD->allowsBuiltInCommand('framing', true))->toBeTrue()
    ->and(EventExecutionScope::BATTLE->allowsBuiltInCommand('framing', true))->toBeFalse()
    ->and(EventExecutionScope::FIELD->allowsBuiltInCommand('camera'))->toBeTrue();
});

it('identifies malformed framing references at the owning boundary', function (): void {
  expect(fn() => new CinematicFramingDefinition(['subjects' => ['selected' => ['kind' => 'npc']]]))
    ->toThrow(InvalidArgumentException::class, 'Framing subject: npc subject requires an id.');
  expect(fn() => CinematicDefinition::fromArrays(['id' => 'synthetic', 'name' => 'Synthetic',
    'presentation' => ['framing' => null]], []))->toThrow(InvalidArgumentException::class, 'Cinematic framing requires');
});
