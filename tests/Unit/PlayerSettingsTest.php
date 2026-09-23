<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Audio\AudioMutePreflight;
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

it('keeps settings and dialogue usable when the Game warning handler meets unwritable player storage', function () {
  $source = "<?php return ['audio' => ['music' => true], 'ui' => ['dialogue' => ['auto' => false]]];\n";
  file_put_contents($this->projectRoot . '/config.php', $source);
  file_put_contents($this->projectRoot . '/blocked', 'not a directory');
  $probe = __DIR__ . '/../Support/PlayerSettingsWarningProbe.php';
  $process = proc_open([PHP_BINARY, $probe, $this->projectRoot],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectRoot);
  expect($process)->not->toBeFalse();
  $output = stream_get_contents($pipes[1]);
  $errors = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($process);

  expect($exitCode)->toBe(0, $errors)
    ->and(json_decode($output, true))->toBe([
      'diagnosed' => true,
      'musicApplied' => true,
      'autoApplied' => true,
    ])
    ->and(file_get_contents($this->projectRoot . '/config.php'))->toBe($source);
});

it('preflights music effects and independent Voice against effective player overrides', function () {
  file_put_contents($this->projectRoot . '/config.php',
    "<?php return ['audio' => ['music' => true, 'sfx' => true, 'voice' => true]];\n");
  $player = new PlayerSettings($this->projectRoot);
  ConfigStore::put(PlayerSettings::class, $player);
  foreach (['audio.music', 'audio.sfx', 'audio.voice'] as $path) {
    $player->set($path, false);
  }
  $player->persist();
  ConfigStore::put(PlayerSettings::class, new PlayerSettings($this->projectRoot));
  $effective = new ProjectConfig();
  AudioMutePreflight::assertMuted($effective);

  $player = ConfigStore::get(PlayerSettings::class);
  $player->set('audio.voice', true);
  ConfigStore::put(ProjectConfig::class, new ProjectConfig());
  expect(fn() => AudioMutePreflight::assertMuted(ConfigStore::get(ProjectConfig::class)))
    ->toThrow(RuntimeException::class, 'audio.voice');
});
