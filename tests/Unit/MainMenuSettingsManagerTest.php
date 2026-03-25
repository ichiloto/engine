<?php

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSetting;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

class InlineConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $segments = explode('.', $path);
    $value = $this->values;

    foreach ($segments as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $segments = explode('.', $path);
    $target = &$this->values;

    foreach ($segments as $segment) {
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
    // Test stub; persistence is not needed.
  }
}

class ProjectConfigPersistProxy extends ProjectConfig
{
  protected function load(): array
  {
    return $this->options['initial'] ?? [];
  }

  protected function getFilename(): string
  {
    return $this->options['filename'];
  }
}

function getMainMenuSettingByKey(MainMenuSettingsManager $manager, string $key): MainMenuSetting
{
  foreach ($manager->getSettings() as $setting) {
    if ($setting->key === $key) {
      return $setting;
    }
  }

  throw new RuntimeException("Setting {$key} not found.");
}

it('updates and persists the selection color for menus and battle', function () {
  $filename = tempnam(sys_get_temp_dir(), 'ichiloto-config-');
  $config = new ProjectConfigPersistProxy([
    'filename' => $filename,
    'initial' => [
      'ui' => [
        'menu' => [
          'selection_color' => Color::LIGHT_BLUE,
        ],
        'battle' => [
          'selection_color' => Color::LIGHT_BLUE,
        ],
      ],
    ],
  ]);
  ConfigStore::put(AppConfig::class, new InlineConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, $config);

  $manager = new MainMenuSettingsManager();
  $setting = getMainMenuSettingByKey($manager, 'selection_color');

  $label = $manager->cycle($setting, 1);
  $contents = file_get_contents($filename);

  expect($label)->toBe('Yellow')
    ->and($config->get('ui.menu.selection_color'))->toBe(Color::YELLOW)
    ->and($config->get('ui.battle.selection_color'))->toBe(Color::YELLOW)
    ->and($contents)->toContain('Color::YELLOW');

  unlink($filename);
});

it('updates dialogue speed on both supported config paths', function () {
  $filename = tempnam(sys_get_temp_dir(), 'ichiloto-config-');
  $config = new ProjectConfigPersistProxy([
    'filename' => $filename,
    'initial' => [
      'ui' => [
        'dialogue' => [
          'speed' => 20,
          'message' => [
            'speed' => 20,
          ],
        ],
      ],
    ],
  ]);
  ConfigStore::put(AppConfig::class, new InlineConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, $config);

  $manager = new MainMenuSettingsManager();
  $setting = getMainMenuSettingByKey($manager, 'dialogue_speed');

  $label = $manager->cycle($setting, 1);

  expect($label)->toBe('Normal')
    ->and($config->get('ui.dialogue.speed'))->toBe(50)
    ->and($config->get('ui.dialogue.message.speed'))->toBe(50);

  unlink($filename);
});
