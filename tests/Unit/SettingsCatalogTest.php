<?php

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
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
