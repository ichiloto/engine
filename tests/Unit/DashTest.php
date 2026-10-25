<?php

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\DashSettings;
use Ichiloto\Engine\Field\PlayerDash;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Settings\SettingsCatalog;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

/** A writable ProjectConfig stand-in, so a setting's write can be read back. */
final class DashConfigStub implements ConfigInterface
{
  public function __construct(public array $values = []) {}

  public function get(string $path, mixed $default = null): mixed
  {
    $value = $this->values;
    foreach (explode('.', $path) as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) { return $default; }
      $value = $value[$segment];
    }
    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $target = &$this->values;
    foreach (explode('.', $path) as $segment) {
      if (! isset($target[$segment]) || ! is_array($target[$segment])) { $target[$segment] = []; }
      $target = &$target[$segment];
    }
    $target = $value;
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();
    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void {}
}

beforeEach(function () {
  $this->saved = [];
  foreach ([InputManager::class, ConfigStore::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  InputManager::setBindings(['down' => ['keys' => [KeyCode::DOWN]]]);
  ConfigStore::put(ProjectConfig::class, $this->config = new DashConfigStub());
});

afterEach(function () {
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('reads the project dash rule from system data and refuses one the game could not use', function () {
  expect(DashSettings::fromSystemData([])->enabled)->toBeFalse()
    ->and(DashSettings::fromSystemData(['dash' => []]))->toEqual(new DashSettings(false, 2.0))
    ->and(DashSettings::fromSystemData(['dash' => ['enabled' => true]]))->toEqual(new DashSettings(true, 2.0))
    ->and(DashSettings::fromSystemData(['dash' => ['enabled' => true, 'speedMultiplier' => 3]]))->toEqual(new DashSettings(true, 3.0))
    ->and(DashSettings::fromSystemData(['dash' => ['enabled' => true, 'speedMultiplier' => 1.5]])->speedMultiplier)->toBe(1.5);

  foreach ([
    [['enabled' => 'yes'], 'true or false'],
    [['speedMultiplier' => '2'], 'must be a number'],
    [['speedMultiplier' => 1], 'greater than 1'],
    [['speedMultiplier' => 0.5], 'greater than 1'],
    [['speedMultiplier' => 4.5], 'at most 4'],
    [['speedMultiplier' => INF], 'greater than 1'],
    [['speed' => 2], 'Unknown system.dash field'],
    [[true], 'associative array'],
    ['fast', 'associative array'],
  ] as [$dash, $message]) {
    expect(fn() => DashSettings::fromSystemData(['dash' => $dash]))->toThrow(InvalidArgumentException::class, $message);
  }
});

it('reads a map turning dashing off only as true or false', function () {
  expect(DashSettings::readMapDisablesDashing([]))->toBeFalse()
    ->and(DashSettings::readMapDisablesDashing(['disableDashing' => false]))->toBeFalse()
    ->and(DashSettings::readMapDisablesDashing(['disableDashing' => true]))->toBeTrue()
    ->and(fn() => DashSettings::readMapDisablesDashing(['disableDashing' => 1]))->toThrow(InvalidArgumentException::class);
});

it('dashes while dash is held, inverts with Always Dash and walks where the map or game offers none', function () {
  InputManager::setInputSource($source = new FakeHeldInputSource());
  ConfigStore::put(DashSettings::class, new DashSettings(true, 2.5));
  InputManager::handleInput();
  expect(PlayerDash::isAvailable())->toBeTrue()->and(PlayerDash::getSpeedMultiplier())->toBe(1.0);
  $source->press('shift', KeyCode::SHIFT);
  InputManager::handleInput();
  expect(PlayerDash::getSpeedMultiplier())->toBe(2.5)
    ->and(PlayerDash::getSpeedMultiplier(mapDisablesDashing: true))->toBe(1.0);

  $this->config->set(PlayerDash::CONFIG_ALWAYS_DASH, true);
  expect(PlayerDash::getSpeedMultiplier())->toBe(1.0);
  $source->release('shift');
  InputManager::handleInput();
  expect(PlayerDash::getSpeedMultiplier())->toBe(2.5)
    ->and(PlayerDash::getSpeedMultiplier(mapDisablesDashing: true))->toBe(1.0);

  ConfigStore::put(DashSettings::class, new DashSettings());
  expect(PlayerDash::isAvailable())->toBeFalse()->and(PlayerDash::getSpeedMultiplier())->toBe(1.0);
  ConfigStore::remove(DashSettings::class);
  expect(PlayerDash::isAvailable())->toBeFalse()->and(PlayerDash::getSpeedMultiplier())->toBe(1.0);
});

it('offers no dash in the Terminal, which cannot report a held control, and hides its control and option there', function () {
  ConfigStore::put(DashSettings::class, new DashSettings(true, 2.0));
  $this->config->set(PlayerDash::CONFIG_ALWAYS_DASH, true);
  $listed = static fn(): array => [array_column(new InputBindings()->all(), 'action'),
    array_map(static fn($setting): string => $setting->key, new MainMenuSettingsManager()->getSettings()),
    array_map(static fn($setting): string => $setting->key, new TitleOptionsSettingsManager()->getSettings())];

  InputManager::setInputSource(new FakeInputSource(KeyCode::SHIFT, KeyCode::DOWN));
  InputManager::handleInput();
  [$controls, $config, $title] = $listed();
  expect(PlayerDash::isAvailable())->toBeFalse()->and(PlayerDash::getSpeedMultiplier())->toBe(1.0)
    ->and($controls)->not->toContain('dash')->and($config)->not->toContain('always_dash')->and($title)->not->toContain('always_dash')
    ->and(new InputBindings()->controlForAction('dash'))->toBeNull();

  InputManager::setInputSource(new FakeHeldInputSource());
  [$controls, $config, $title] = $listed();
  expect($controls)->toContain('dash')->and($config)->toContain('always_dash')->and($title)->toContain('always_dash')
    ->and(new InputBindings()->controlForAction('dash'))->toEqual(ControlHint::keyboard(KeyCode::SHIFT));

  // A game without a dash offers neither, whatever the input.
  ConfigStore::put(DashSettings::class, new DashSettings());
  [$controls, $config] = $listed();
  expect($controls)->not->toContain('dash')->and($config)->not->toContain('always_dash');
});

it('binds dash to Shift where nothing claims it, keycapped as Shift, with room for a controller button', function () {
  $dash = InputManager::getBindings()['dash'];
  expect($dash['keys'])->toBe([KeyCode::SHIFT])
    ->and(array_column($dash['controllers'], 'control'))->toBe(['face_east', 'face_east'])
    ->and(InputManager::getDefaultDashBinding(['run' => ['keys' => [KeyCode::SHIFT]]])['keys'])->toBe([])
    ->and(ControlHint::keyboard(KeyCode::SHIFT)->label)->toBe('Shift')
    ->and(TerminalControlText::formatControl(ControlHint::keyboard(KeyCode::SHIFT)))->toBe('[Shift]');
  // An authored dash binding, unbound or not, is kept as written.
  InputManager::setBindings(['dash' => ['description' => 'Run.', 'keys' => [KeyCode::r]]]);
  expect(InputManager::getBindings()['dash'])->toBe(['description' => 'Run.', 'keys' => [KeyCode::r]]);
});

it('keeps Always Dash a player setting that the project may author first', function () {
  InputManager::setInputSource(new FakeHeldInputSource());
  ConfigStore::put(DashSettings::class, new DashSettings(true, 2.0));
  $root = sys_get_temp_dir() . '/ichiloto-dash-' . bin2hex(random_bytes(4));
  mkdir($root);
  try {
    ConfigStore::put(PlayerSettings::class, $player = new PlayerSettings($root));
    $catalog = new SettingsCatalog();
    expect($catalog->read('always_dash'))->toBeFalse();
    $this->config->set(PlayerDash::CONFIG_ALWAYS_DASH, true);
    expect($catalog->read('always_dash'))->toBeTrue();
    $catalog->write('always_dash', false);
    expect($catalog->read('always_dash'))->toBeFalse()
      ->and($player->getOverrides())->toBe(['field' => ['always_dash' => false]]);
  } finally {
    rmdir($root);
  }
});

it('plays the walk faster while dashing, one pattern per stride of travel', function () {
  putSceneAudioConfig([]);
  $metric = new FieldMetric();
  // Frames between pattern changes while holding a direction for a second at a pace.
  $changes = static function (float $multiplier) use ($metric): int {
    $animation = new CharacterWalkAnimation($metric);
    $position = new Vector2(10, 10);
    $untilNext = 0.0;
    $patterns = [];
    for ($frame = 0; $frame < 60; $frame++) {
      if ($untilNext <= 1e-9) {
        $from = clone $position;
        $position = Vector2::sum($position, Vector2::right());
        $seconds = $metric->getWalkSeconds(Vector2::right()) / $multiplier;
        $animation->step(new CharacterStep($from, $position, $seconds));
        $untilNext += $seconds;
      }
      $patterns[] = $animation->getPattern();
      $animation->advance(1 / 60);
      $untilNext -= 1 / 60;
    }
    return count(array_filter(array_keys($patterns), static fn(int $i): bool => $i > 0 && $patterns[$i] !== $patterns[$i - 1]));
  };
  // Walking changes pattern every 10 frames (30 field pixels at 180 per second); a double-speed dash every 5.
  expect($changes(1.0))->toBe(5)->and($changes(2.0))->toBe(11);
});
