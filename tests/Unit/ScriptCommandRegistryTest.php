<?php

use Ichiloto\Engine\Events\Interpreter\Commands\InnCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandDefinition;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandFieldKind;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandHandlerInterface;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandOutcome;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Events\Interpreter\Commands\ShopCommand;

final class RegistryTestCarriageCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    return ScriptCommandOutcome::complete();
  }
}

final class RegistryTestNotAHandler
{
}

/** A project command as a project declares it. */
function makeCarriageDeclaration(array $overrides = []): array
{
  return array_replace([
    'type' => 'hire_carriage',
    'class' => RegistryTestCarriageCommand::class,
    'label' => 'Hire Carriage',
    'description' => 'Takes the party along a road for a fare.',
    'fields' => [
      ['key' => 'destination', 'label' => 'Destination', 'kind' => 'reference', 'reference' => 'map', 'required' => true],
      ['key' => 'arrival', 'label' => 'Arrival', 'kind' => 'position', 'required' => true],
      ['key' => 'fare', 'label' => 'Fare', 'kind' => 'integer', 'minimum' => 0],
      ['key' => 'speed', 'label' => 'Speed', 'kind' => 'option', 'options' => ['slow', 'fast']],
      ['key' => 'scenic', 'label' => 'Scenic Route', 'kind' => 'boolean'],
      ['key' => 'driver.name', 'label' => 'Driver', 'kind' => 'text'],
      [
        'key' => 'stops',
        'label' => 'Stops',
        'kind' => 'list',
        'fields' => [
          ['key' => 'map', 'label' => 'Map', 'kind' => 'reference', 'reference' => 'map', 'required' => true],
          ['key' => 'minutes', 'label' => 'Minutes', 'kind' => 'number', 'minimum' => 0.5],
        ],
      ],
    ],
  ], $overrides);
}

/** Writes a disposable asset root holding the given declaration file source. */
function makeScriptCommandProject(?string $source): string
{
  $root = sys_get_temp_dir() . '/script-commands-' . uniqid();
  mkdir($root . '/Data', 0o777, true);

  if ($source !== null) {
    file_put_contents($root . '/' . ScriptCommandRegistry::PROJECT_FILE, $source);
  }

  return $root;
}

afterEach(function () {
  ScriptCommandRegistry::reset();
});

it('registers the Engine shop and inn for every project', function () {
  $catalog = ScriptCommandCatalog::createEngineCatalog();

  expect($catalog->types)->toBe(['shop', 'inn'])
    ->and($catalog->findDefinition('shop')?->handlerClass)->toBe(ShopCommand::class)
    ->and($catalog->findDefinition('inn')?->handlerClass)->toBe(InnCommand::class)
    ->and($catalog->createHandler('shop'))->toBeInstanceOf(ShopCommand::class)
    ->and($catalog->findDefinition('transfer'))->toBeNull()
    ->and(ScriptCommandRegistry::getCatalog()->types)->toBe(['shop', 'inn']);
  $catalog->assertHandlersLoadable();
});

it('adds project commands after the Engine commands with their declared fields', function () {
  $catalog = ScriptCommandCatalog::fromDeclarations([makeCarriageDeclaration()], 'test project');
  $definition = $catalog->findDefinition('hire_carriage');

  expect($catalog->types)->toBe(['shop', 'inn', 'hire_carriage'])
    ->and($definition?->label)->toBe('Hire Carriage')
    ->and($definition?->description)->toBe('Takes the party along a road for a fare.')
    ->and(array_map(static fn($field): string => $field->key, $definition->fields))
    ->toBe(['destination', 'arrival', 'fare', 'speed', 'scenic', 'driver.name', 'stops'])
    ->and($definition->fields[0]->reference)->toBe(ScriptCommandReference::MAP)
    ->and($definition->fields[6]->kind)->toBe(ScriptCommandFieldKind::LIST)
    ->and($definition->fields[6]->fields[1]->minimum)->toBe(0.5)
    ->and($catalog->createHandler('hire_carriage'))->toBeInstanceOf(RegistryTestCarriageCommand::class);
});

it('accepts commands that give what their fields require', function () {
  $definition = ScriptCommandDefinition::fromArray(makeCarriageDeclaration(), 'test');

  expect($definition->findProblems([
    'type' => 'hire_carriage',
    'destination' => 'harbour',
    'arrival' => ['x' => 4, 'y' => 9],
    'fare' => 0,
    'speed' => 'fast',
    'scenic' => false,
    'driver' => ['name' => 'Odo'],
    'stops' => [['map' => 'mill', 'minutes' => 2], ['map' => 'ford']],
  ]))->toBe([])
    ->and($definition->findProblems(['destination' => 'harbour', 'arrival' => ['x' => 0, 'y' => 0]]))->toBe([]);
});

it('names every field a command gets wrong', function (array $command, array $problems) {
  $definition = ScriptCommandDefinition::fromArray(makeCarriageDeclaration(), 'test');

  expect($definition->findProblems(['destination' => 'harbour', 'arrival' => ['x' => 1, 'y' => 1], ...$command]))
    ->toBe($problems);
})->with([
  'missing required' => [['destination' => '  ', 'arrival' => null], ['"destination" is required.', '"arrival" is required.']],
  'reference not text' => [['destination' => 12], ['"destination" must be text.']],
  'position without y' => [['arrival' => ['x' => 1]], ['"arrival" must give whole-number x and y.']],
  'fractional integer' => [['fare' => 2.5], ['"fare" must be a whole number.']],
  'below minimum' => [['fare' => -1], ['"fare" must be at least 0.']],
  'unknown option' => [['speed' => 'warp'], ['"speed" must be one of slow, fast.']],
  'boolean as text' => [['scenic' => 'yes'], ['"scenic" must be true or false.']],
  'nested text' => [['driver' => ['name' => 3]], ['"driver.name" must be text.']],
  'list not a list' => [['stops' => ['map' => 'mill']], ['"stops" must be a list.']],
  'list entries' => [
    ['stops' => [['minutes' => 0.25], 'mill']],
    ['"stops[1].map" is required.', '"stops[1].minutes" must be at least 0.5.', '"stops[2]" must be a keyed entry.'],
  ],
]);

it('requires a required list to have entries', function () {
  $definition = ScriptCommandCatalog::createEngineCatalog()->findDefinition('shop');

  expect($definition?->findProblems(['items' => []]))->toBe(['"items" needs at least one entry.'])
    ->and($definition?->findProblems(['items' => [['item' => 'Potion', 'price' => 10]], 'sellRate' => 0.25]))->toBe([]);
});

it('refuses malformed project declarations with their place', function (mixed $declarations, string $message) {
  expect(fn() => ScriptCommandCatalog::fromDeclarations($declarations, 'test project'))
    ->toThrow(InvalidArgumentException::class, $message);
})->with([
  'not a list' => [['carriage' => makeCarriageDeclaration()], 'test project must return a list'],
  'built-in type' => [[makeCarriageDeclaration(['type' => 'transfer'])], '"transfer", a built-in command type'],
  'Engine type' => [[makeCarriageDeclaration(['type' => 'shop'])], '"shop", which is already registered'],
  'repeated type' => [[makeCarriageDeclaration(), makeCarriageDeclaration()], 'command 2 uses "hire_carriage", which is already registered'],
  'type spelling' => [[makeCarriageDeclaration(['type' => 'Hire-Carriage'])], 'test project command 1 needs a type of lower-case words'],
  'no class' => [[makeCarriageDeclaration(['class' => ''])], 'needs the class of its handler'],
  'no label' => [[makeCarriageDeclaration(['label' => ' '])], '(hire_carriage) needs a label'],
  'field type key' => [[makeCarriageDeclaration(['fields' => [['key' => 'type', 'label' => 'Type', 'kind' => 'text']]])], 'cannot use the key "type"'],
  'field kind' => [[makeCarriageDeclaration(['fields' => [['key' => 'fare', 'label' => 'Fare', 'kind' => 'money']]])], '(fare) needs a kind'],
  'reference resource' => [[makeCarriageDeclaration(['fields' => [['key' => 'to', 'label' => 'To', 'kind' => 'reference', 'reference' => 'planet']]])], '(to) needs the resource it references'],
  'stray reference' => [[makeCarriageDeclaration(['fields' => [['key' => 'to', 'label' => 'To', 'kind' => 'text', 'reference' => 'map']]])], '(to) declares a reference but is not a reference field'],
  'options' => [[makeCarriageDeclaration(['fields' => [['key' => 'speed', 'label' => 'Speed', 'kind' => 'option', 'options' => ['slow', 'slow']]]])], '(speed) needs a list of distinct, non-empty options'],
  'list in list' => [[makeCarriageDeclaration(['fields' => [[
    'key' => 'stops', 'label' => 'Stops', 'kind' => 'list',
    'fields' => [['key' => 'legs', 'label' => 'Legs', 'kind' => 'list', 'fields' => [['key' => 'x', 'label' => 'X', 'kind' => 'integer']]]],
  ]]])], '(legs) cannot be a list inside a list'],
  'empty list fields' => [[makeCarriageDeclaration(['fields' => [['key' => 'stops', 'label' => 'Stops', 'kind' => 'list', 'fields' => []]]])], '(stops) needs the fields of each entry'],
  'text minimum' => [[makeCarriageDeclaration(['fields' => [['key' => 'note', 'label' => 'Note', 'kind' => 'text', 'minimum' => 1]]])], '(note) declares a minimum but is not a number field'],
  'overlapping keys' => [[makeCarriageDeclaration(['fields' => [
    ['key' => 'driver', 'label' => 'Driver', 'kind' => 'text'],
    ['key' => 'driver.name', 'label' => 'Driver Name', 'kind' => 'text'],
  ]])], 'declares "driver.name" over "driver"'],
]);

it('keeps the Engine commands when a project declares none', function () {
  ScriptCommandRegistry::configureFromProject(makeScriptCommandProject(null));

  expect(ScriptCommandRegistry::getCatalog()->types)->toBe(['shop', 'inn']);
});

it('loads the project declaration file and forgets a prior project', function () {
  $carriage = var_export(makeCarriageDeclaration(), true);
  ScriptCommandRegistry::configureFromProject(makeScriptCommandProject("<?php\nreturn [{$carriage}];\n"));

  expect(ScriptCommandRegistry::getCatalog()->isRegistered('hire_carriage'))->toBeTrue();

  ScriptCommandRegistry::configureFromProject(makeScriptCommandProject(null));

  expect(ScriptCommandRegistry::getCatalog()->isRegistered('hire_carriage'))->toBeFalse();
});

it('fails at load when a declared handler cannot run', function (string $class) {
  $declaration = var_export(makeCarriageDeclaration(['class' => $class]), true);
  $root = makeScriptCommandProject("<?php\nreturn [{$declaration}];\n");

  expect(fn() => ScriptCommandRegistry::configureFromProject($root))
    ->toThrow(RuntimeException::class, sprintf('names handler %s, which must exist and implement', $class));
  expect(ScriptCommandRegistry::getCatalog()->isRegistered('hire_carriage'))->toBeFalse();
})->with([
  'missing class' => ['Project\\Commands\\MissingCarriage'],
  'not a handler' => [RegistryTestNotAHandler::class],
]);

it('names the declaration file when it cannot be read', function () {
  $root = makeScriptCommandProject("<?php\nreturn 'carriages';\n");

  expect(fn() => ScriptCommandRegistry::configureFromProject($root))
    ->toThrow(RuntimeException::class, 'Script commands ' . $root . '/' . ScriptCommandRegistry::PROJECT_FILE . ' could not be loaded');
});

it('validates registered commands in authored scripts through their definitions', function () {
  Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator::validate([
    ['type' => 'branch', 'conditions' => [], 'then' => [['type' => 'shop', 'items' => [['item' => 'Potion']]]]],
  ], 'registered');

  expect(fn() => Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator::validate([
    ['type' => 'inn', 'cost' => -5],
  ], 'registered'))->toThrow(
    InvalidArgumentException::class,
    'Cinematic "registered" is invalid at command path "script[1]": "confirmDialogue.text" is required. "cost" must be at least 0.',
  );
});

it('refuses authored skipping across a registered command', function () {
  expect(fn() => Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandPolicy::assertAuthoredSkipSafe([
    ['type' => 'text', 'text' => 'Welcome.'],
    ['type' => 'shop', 'items' => [['item' => 'Potion']]],
  ], 'registered'))->toThrow(InvalidArgumentException::class, 'cannot prove registered command "shop" safe');
});
