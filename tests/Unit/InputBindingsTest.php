<?php

use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

beforeEach(function () {
  $this->saved = [];
  foreach ([InputManager::class, ConfigStore::class, EventManager::class, Debug::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->playerRoot = sys_get_temp_dir() . '/ichiloto-bindings-' . bin2hex(random_bytes(6));
  mkdir($this->playerRoot);
  Debug::configure(['log_directory' => $this->playerRoot . '/logs']);
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->playerRoot));
});

afterEach(function () {
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->playerRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->playerRoot);
});

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
  new ReflectionProperty(InputManager::class, 'defaultConfig')->setValue(null, InputManager::getBindings());

  return $config;
}

function savedBindingValues(string $root, string $action): ?array
{
  $filename = $root . '/.data/player-settings.json';
  if (! is_file($filename)) { return null; }
  $data = json_decode((string) file_get_contents($filename), true);
  return $data['input']['bindings'][$action] ?? null;
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
  expect($actions)->toBe(['action', 'up', 'info', 'dialogue_auto'])
    ->and($bindings->describeKeys('action'))->toBe('SPACE, ENTER')
    ->and($bindings->describeKeys('up'))->toBe('UP, W')
    ->and($bindings->describeKeys('info'))->toBe('i, I');
});

it('rebinds an action immediately without rewriting authored input', function () {
  $config = installBindings(demoBindings());
  $bindings = new InputBindings();

  expect($bindings->rebind('up', KeyCode::K))->toBeTrue()
    ->and($bindings->describeKeys('up'))->toBe('K')
    // The running game reads bindings from the manager, so this takes effect
    // without a restart.
    ->and(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::K])
    ->and($config->all()['up']['keys'])->toBe([KeyCode::UP, KeyCode::W])
    ->and($config->written)->toBeEmpty()
    ->and(savedBindingValues($this->playerRoot, 'up'))->toBe([KeyCode::K->value]);
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

it('selects one currently bound primary control while Controls retains every alias', function () {
  installBindings([
    'confirm' => ['keys' => [KeyCode::SPACE, KeyCode::ENTER]],
    'cancel' => ['keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE]],
    'back' => ['keys' => [KeyCode::END, KeyCode::ESCAPE]],
  ]);
  $bindings = new InputBindings();
  expect($bindings->primaryKey('confirm'))->toBe(KeyCode::ENTER)
    ->and($bindings->primaryKey('cancel'))->toBe(KeyCode::ESCAPE)
    ->and($bindings->primaryKey('back'))->toBe(KeyCode::ESCAPE)
    ->and($bindings->describeKeys('cancel'))->toBe('C, c, ESCAPE')
    ->and($bindings->describeKeys('confirm'))->toBe('SPACE, ENTER')
    ->and($bindings->controlForAction('cancel')->label)->toBe('Escape');
  $bindings->rebind('cancel', KeyCode::Q);
  expect($bindings->primaryKey('cancel'))->toBe(KeyCode::Q)
    ->and($bindings->controlForAction('cancel')->control)->toBe('Q')
    ->and($bindings->describeKeys('cancel'))->toBe('Q');
});

it('does not invent preferred controls for empty missing or differently bound actions', function () {
  installBindings(['confirm' => ['keys' => [KeyCode::F2, KeyCode::SPACE]], 'cancel' => ['keys' => []]]);
  $bindings = new InputBindings();
  expect($bindings->primaryKey('confirm'))->toBe(KeyCode::F2)
    ->and($bindings->primaryKey('cancel'))->toBeNull()->and($bindings->primaryKey('back'))->toBeNull()
    ->and($bindings->controlForAction('cancel'))->toBeNull()->and($bindings->describeKeys('cancel'))->toBe('Unbound');
});

it('discovers a fully conflicted Info default as unbound and permits an explicit rebind', function () {
  $authored = ['custom' => ['description' => 'Custom action.', 'keys' => [KeyCode::i, KeyCode::I]]];
  $config = installBindings($authored);
  $bindings = new InputBindings();
  expect(array_column($bindings->all(), 'action'))->toBe(['custom', 'info', 'dialogue_auto'])
    ->and($bindings->describeKeys('info'))->toBe('Unbound')
    ->and($bindings->controlForAction('info'))->toBeNull()
    ->and($config->all())->toBe($authored)->and($config->written)->toBeEmpty();
  expect($bindings->rebind('info', KeyCode::F2))->toBeTrue()
    ->and($bindings->describeKeys('info'))->toBe('F2')
    ->and(InputManager::getBindings()['custom'])->toBe($authored['custom'])
    ->and($config->written)->toBeEmpty()
    ->and(savedBindingValues($this->playerRoot, 'info'))->toBe([KeyCode::F2->value]);
});

it('loads player keys over authored defaults and restores defaults without editing input.php', function () {
  $originalDirectory = getcwd();
  $source = "<?php return ['up' => ['description' => 'Walk north.', 'keys' => [\\Ichiloto\\Engine\\IO\\Enumerations\\KeyCode::UP, \\Ichiloto\\Engine\\IO\\Enumerations\\KeyCode::W], 'controllers' => [['family' => 'gamepad.xbox', 'control' => 'dpad_up', 'label' => 'Up']]], 'back' => ['description' => 'Leave.', 'keys' => [\\Ichiloto\\Engine\\IO\\Enumerations\\KeyCode::ESCAPE]]];\n";
  file_put_contents($this->playerRoot . '/input.php', $source);
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  try {
    chdir($this->playerRoot);
    ConfigStore::put(InputConfig::class, new InputConfig());
    InputManager::init($game);
    expect((new InputBindings())->rebind('up', KeyCode::K))->toBeTrue()
      ->and(savedBindingValues($this->playerRoot, 'up'))->toBe([KeyCode::K->value])
      ->and(file_get_contents($this->playerRoot . '/input.php'))->toBe($source);

    ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->playerRoot));
    ConfigStore::put(InputConfig::class, new InputConfig());
    InputManager::init($game);
    expect(InputManager::getDefaultBindings()['up']['keys'])->toBe([KeyCode::UP, KeyCode::W])
      ->and(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::K])
      ->and(InputManager::getBindings()['up']['description'])->toBe('Walk north.')
      ->and(InputManager::getBindings()['up']['controllers'])->toBe([['family' => 'gamepad.xbox', 'control' => 'dpad_up', 'label' => 'Up']])
      ->and((new InputBindings())->describeKeys('up'))->toBe('K');
    InputManager::setInputSource(new FakeInputSource(KeyCode::K));
    InputManager::handleInput();
    expect(\Ichiloto\Engine\IO\Input::isButtonDown('up'))->toBeTrue();

    expect((new InputBindings())->restoreDefaults())->toBeTrue()
      ->and(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::UP, KeyCode::W])
      ->and(file_get_contents($this->playerRoot . '/input.php'))->toBe($source);
    ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->playerRoot));
    InputManager::init($game);
    expect(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::UP, KeyCode::W]);
  } finally {
    chdir($originalDirectory);
  }
});

it('ignores stale locked and invalid player keys while preserving authored actions', function () {
  mkdir($this->playerRoot . '/.data');
  file_put_contents($this->playerRoot . '/.data/player-settings.json', json_encode([
    'input' => ['bindings' => [
      'up' => ['not-a-key'],
      'back' => [KeyCode::K->value],
      'retired' => [KeyCode::K->value],
      'info' => [],
    ]],
  ], JSON_THROW_ON_ERROR));
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->playerRoot));
  ConfigStore::put(InputConfig::class, new RecordingInputConfig(['initial' => demoBindings()]));
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };

  InputManager::init($game);
  expect(InputManager::getBindings()['up']['keys'])->toBe([KeyCode::UP, KeyCode::W])
    ->and(InputManager::getBindings()['back']['keys'])->toBe([KeyCode::ESCAPE])
    ->and(InputManager::getBindings()['info']['keys'])->toBe([])
    ->and(InputManager::getBindings())->not->toHaveKey('retired')
    ->and(file_get_contents($this->playerRoot . '/logs/warning.log'))
    ->toContain('invalid player input binding for up', 'obsolete or locked player input binding');
});
