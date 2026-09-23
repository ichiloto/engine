<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Settings\SettingsCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->originalDirectory = getcwd();
  $this->projectRoot = sys_get_temp_dir() . '/ichiloto-player-settings-' . bin2hex(random_bytes(6));
  mkdir($this->projectRoot);
  chdir($this->projectRoot);
  $this->previousConfigs = new ReflectionClass(ConfigStore::class)->getStaticProperties();
});

afterEach(function () {
  chdir($this->originalDirectory);
  foreach ($this->previousConfigs as $name => $value) {
    new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value);
  }
  $files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($this->projectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );
  foreach ($files as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
  }
  rmdir($this->projectRoot);
});

it('reloads every player-facing setting without rewriting authored project defaults', function () {
  $source = "<?php return ['audio' => ['music' => false], 'save' => ['autosave' => true]];\n";
  file_put_contents($this->projectRoot . '/config.php', $source);
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->projectRoot));
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());

  $catalog = new SettingsCatalog();
  $chosen = [];
  foreach ($catalog->all() as $key => $setting) {
    $choices = array_values($setting->choices);
    $catalog->write($key, $choices[array_key_last($choices)]);
    $chosen[$key] = $catalog->read($key);
  }
  $catalog->persist();

  expect(file_get_contents($this->projectRoot . '/config.php'))->toBe($source)
    ->and(is_file($this->projectRoot . '/.data/player-settings.json'))->toBeTrue();
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->projectRoot));
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());
  foreach ($chosen as $key => $value) {
    expect($catalog->read($key))->toBe($value);
  }
  expect(ConfigStore::get(ProjectConfig::class)->get('save.autosave'))->toBeTrue()
    ->and($catalog->read('selection_color'))->toBe(Color::WHITE);
});

it('limits player overrides to settings owned by the shared catalog', function () {
  file_put_contents($this->projectRoot . '/config.php',
    "<?php return ['save' => ['autosave' => true], 'audio' => ['music' => true]];\n");
  mkdir($this->projectRoot . '/.data');
  file_put_contents($this->projectRoot . '/.data/player-settings.json',
    '{"save":{"autosave":false},"audio":{"music":false,"voice":[]}}');
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->projectRoot));
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());

  expect(ConfigStore::get(ProjectConfig::class)->get('save.autosave'))->toBeTrue()
    ->and(ConfigStore::get(ProjectConfig::class)->get('audio.music'))->toBeFalse()
    ->and(ConfigStore::get(ProjectConfig::class)->has('audio.voice'))->toBeFalse();
});
