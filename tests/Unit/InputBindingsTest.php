<?php

use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

/**
 * An InputConfig that loads from memory and records what it wrote, so the
 * rebinding path can be exercised without touching a project.
 */
class RecordingInputConfig extends InputConfig
{
  public array $written = [];

  protected function load(): array
  {
    return $this->options['initial'] ?? [];
  }

  protected function getFilename(): string
  {
    return $this->options['filename'] ?? 'input.php';
  }

  public function persist(): void
  {
    $this->written = $this->all();
  }
}

/**
 * Installs a fresh set of bindings on the input manager and config store.
 *
 * @param array<string, array{description: string, keys: KeyCode[]}> $bindings The bindings.
 * @return RecordingInputConfig The installed config.
 */
function installBindings(array $bindings): RecordingInputConfig
{
  $config = new RecordingInputConfig(['initial' => $bindings, 'filename' => 'input.php']);
  ConfigStore::put(InputConfig::class, $config);
  InputManager::setBindings($bindings);

  return $config;
}

function demoBindings(): array
{
  return [
    'action' => ['description' => 'Perform an action.', 'keys' => [KeyCode::SPACE, KeyCode::ENTER]],
    'back' => ['description' => 'Go back.', 'keys' => [KeyCode::ESCAPE]],
    'up' => ['description' => 'Move up.', 'keys' => [KeyCode::UP, KeyCode::W]],
  ];
}

it('lists the rebindable actions with their keys', function () {
  installBindings(demoBindings());
  $bindings = new InputBindings();

  $actions = array_column($bindings->all(), 'action');

  // Back is deliberately absent: escape is how every screen is left,
  // including the rebinding screen.
  expect($actions)->toBe(['action', 'up'])
    ->and($bindings->describeKeys('action'))->toBe('SPACE, ENTER')
    ->and($bindings->describeKeys('up'))->toBe('UP, W');
});

it('rebinds an action immediately and writes it back', function () {
  $config = installBindings(demoBindings());
  $bindings = new InputBindings();

  expect($bindings->rebind('up', KeyCode::K))->toBeTrue()
    ->and($bindings->describeKeys('up'))->toBe('K')
    // The running game reads bindings from the manager, so this takes effect
    // without a restart.
    ->and(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::K])
    ->and($config->written['up']['keys'])->toBe([KeyCode::K]);
});

it('uses rebound directional actions for virtual axes', function () {
  installBindings([
    'up' => ['description' => 'Move up.', 'keys' => [KeyCode::UP]],
    'down' => ['description' => 'Move down.', 'keys' => [KeyCode::DOWN]],
    'left' => ['description' => 'Move left.', 'keys' => [KeyCode::LEFT]],
    'right' => ['description' => 'Move right.', 'keys' => [KeyCode::RIGHT]],
  ]);

  expect((new InputBindings())->rebind('up', KeyCode::K))->toBeTrue();

  $originalSource = InputManager::getInputSource();
  try {
    InputManager::setInputSource(new FakeInputSource(KeyCode::K, KeyCode::UP));
    InputManager::handleInput();
    expect(InputManager::getAxis(AxisName::VERTICAL))->toBe(-1.0);
    InputManager::handleInput();
    expect(InputManager::getAxis(AxisName::VERTICAL))->toBe(0.0);
  } finally {
    InputManager::setInputSource($originalSource);
  }
});

it('refuses to rebind the way out of a screen', function () {
  installBindings(demoBindings());
  $bindings = new InputBindings();

  expect($bindings->rebind('back', KeyCode::B))->toBeFalse()
    ->and(InputManager::getBindings()['back']['keys'])->toBe([KeyCode::ESCAPE]);
});

it('reports an unknown action rather than inventing a binding', function () {
  installBindings(demoBindings());

  expect(new InputBindings()->rebind('somersault', KeyCode::S))->toBeFalse()
    ->and(InputManager::getBindings())->not->toHaveKey('somersault');
});

it('keeps a key that drives more than one action', function () {
  installBindings([
    'cancel' => ['description' => 'Cancel.', 'keys' => [KeyCode::C]],
    'menu' => ['description' => 'Open the menu.', 'keys' => [KeyCode::C]],
  ]);
  $bindings = new InputBindings();

  $bindings->rebind('menu', KeyCode::M);

  // Rebinding one action must not silently unbind another that shared a key.
  expect($bindings->describeKeys('cancel'))->toBe('C')
    ->and($bindings->describeKeys('menu'))->toBe('M');
});

it('describes an action with nothing bound to it', function () {
  installBindings(['dance' => ['description' => 'Dance.', 'keys' => []]]);

  expect(new InputBindings()->describeKeys('dance'))->toBe('Unbound');
});
