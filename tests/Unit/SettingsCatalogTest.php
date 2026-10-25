<?php

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\Settings\SettingsCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

class CatalogConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $value = $this->values;

    foreach (explode('.', $path) as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $target = &$this->values;

    foreach (explode('.', $path) as $segment) {
      if (! isset($target[$segment]) || ! is_array($target[$segment])) {
        $target[$segment] = [];
      }

      $target = &$target[$segment];
    }

    $target = $value;
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
    // Test stub; persistence is covered by the manager tests.
  }
}

it('defines each setting exactly once', function () {
  $catalog = new SettingsCatalog();
  $all = $catalog->all();

  expect($all)->not->toBeEmpty();

  foreach ($all as $key => $setting) {
    expect($setting)->toBeInstanceOf(GameSetting::class)
      ->and($setting->key)->toBe($key)
      ->and($setting->label)->not->toBe('')
      ->and($setting->description)->not->toBe('')
      ->and($setting->choices)->not->toBeEmpty();
  }
});

it('returns the selected settings in the order asked for, skipping unknown keys', function () {
  $catalog = new SettingsCatalog();

  $selected = $catalog->select('sfx', 'volume', 'no_such_setting');

  expect(array_map(static fn(GameSetting $s): string => $s->key, $selected))->toBe(['sfx', 'volume']);
});

it('gives both surfaces the same setting object', function () {
  $inGame = new MainMenuSettingsManager();
  $title = new TitleOptionsSettingsManager();

  $findVolume = static function (array $settings): GameSetting {
    foreach ($settings as $setting) {
      if ($setting->key === 'volume') {
        return $setting;
      }
    }

    throw new RuntimeException('Volume is missing from a settings surface.');
  };

  // The point of the shared catalog: adjusting volume in the field means the
  // same thing as adjusting it from the title screen.
  expect($findVolume($inGame->getSettings())->choices)
    ->toBe($findVolume($title->getOptions())->choices);
});

it('reads and writes a setting through its config paths', function () {
  $config = new CatalogConfigStub(['audio' => ['master_volume' => 40, 'music' => true]]);
  ConfigStore::put(ProjectConfig::class, $config);

  $catalog = new SettingsCatalog();

  expect($catalog->read('volume'))->toBe(40)
    ->and($catalog->read('music'))->toBeTrue();

  $catalog->write('volume', 65);
  $catalog->write('music', false);

  expect($config->get('audio.master_volume'))->toBe(65)
    ->and($config->get('audio.music'))->toBeFalse();
});

it('writes dialogue speed to both paths a project may read', function () {
  $config = new CatalogConfigStub([]);
  ConfigStore::put(ProjectConfig::class, $config);

  new SettingsCatalog()->write('dialogue_speed', 80);

  expect($config->get('ui.dialogue.speed'))->toBe(80)
    ->and($config->get('ui.dialogue.message.speed'))->toBe(80);
});

it('exposes independent Voice in both settings menus without changing SFX', function () {
  $config = new CatalogConfigStub(['audio'=>['sfx'=>false, 'voice'=>true]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $catalog = new SettingsCatalog();
  foreach ([new MainMenuSettingsManager(), new TitleOptionsSettingsManager()] as $manager) {
    expect(array_column($manager->getSettings(), 'key'))->toContain('voice');
  }
  expect($catalog->read('voice'))->toBeTrue()->and($catalog->read('sfx'))->toBeFalse();
  $catalog->write('voice', false);
  $catalog->write('sfx', true);
  expect($catalog->read('voice'))->toBeFalse()->and($catalog->read('sfx'))->toBeTrue();
});

it('reads and writes the player notification duration profile', function () {
  $config = new CatalogConfigStub([]);
  ConfigStore::put(ProjectConfig::class, $config);

  $catalog = new SettingsCatalog();

  expect($catalog->read('notification_duration'))->toBe(1.0);

  $catalog->write('notification_duration', 10.0);

  expect($config->get('accessibility.notificationDurationScale'))->toBe(10.0)
    ->and($catalog->read('notification_duration'))->toBe(10.0);
});

it('lets a player choose a screen transition', function () {
  $config = new CatalogConfigStub([]);
  ConfigStore::put(ProjectConfig::class, $config);

  $catalog = new SettingsCatalog();

  expect($catalog->read('transitions'))->toBe(TransitionStyle::NONE);

  $catalog->write('transitions', TransitionStyle::WIPE);

  expect($config->get('ui.transitions.style'))->toBe('wipe')
    ->and($catalog->read('transitions'))->toBe(TransitionStyle::WIPE);
});

it('exposes and persists battle transitions independently while preserving legacy defaults', function () {
  $config = new CatalogConfigStub(['ui' => ['transitions' => ['style' => 'none']]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $catalog = new SettingsCatalog();
  expect($catalog->read('battle_transitions'))->toBeFalse();
  $catalog->write('battle_transitions', true);
  expect($catalog->read('battle_transitions'))->toBeTrue()
    ->and($config->get('ui.transitions.battle'))->toBeTrue()
    ->and($config->get('ui.transitions.style'))->toBe('none');
  $catalog->write('transitions', TransitionStyle::WIPE);
  $catalog->write('battle_transitions', false);
  expect($catalog->read('battle_transitions'))->toBeFalse()
    ->and($catalog->read('transitions'))->toBe(TransitionStyle::WIPE);
  foreach ([new MainMenuSettingsManager(), new TitleOptionsSettingsManager()] as $manager) {
    expect(array_column($manager->getSettings(), 'key'))->toContain('battle_transitions');
  }
});

it('offers a volume for every audio bus beside the master volume on both settings surfaces', function () {
  $config = new CatalogConfigStub(['audio' => ['master_volume' => 75, 'music_volume' => 62, 'sfx' => false]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $catalog = new SettingsCatalog();
  $master = $catalog->all()['volume'];

  foreach ([new MainMenuSettingsManager(), new TitleOptionsSettingsManager()] as $manager) {
    $settings = $manager->getSettings();
    expect(array_slice(array_column($settings, 'key'), 0, 4))->toBe(['volume', 'music_volume', 'sfx_volume', 'voice_volume'])
      ->and(array_slice(array_column($settings, 'label'), 0, 4))
      ->toBe(['Master Volume', 'Music Volume', 'Sound Effects Volume', 'Voice Volume']);
    foreach (array_slice($settings, 1, 3) as $setting) {
      expect($setting->choices)->toBe($master->choices)->and($setting->wraps)->toBeFalse();
    }
  }
  // An authored volume between steps shows as the nearest step; a bus the game leaves out reads full.
  expect($catalog->read('music_volume'))->toBe(60)->and($catalog->read('voice_volume'))->toBe(100);
  $catalog->write('sfx_volume', 35);
  expect($config->get('audio.sfx_volume'))->toBe(35)->and($config->get('audio.sfx'))->toBeFalse()
    ->and($config->get('audio.master_volume'))->toBe(75);
});
