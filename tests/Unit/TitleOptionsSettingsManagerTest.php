<?php

use Ichiloto\Engine\Scenes\Title\TitleOption;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

class TitleInlineConfigStub implements ConfigInterface
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

class TitleProjectConfigPersistProxy extends ProjectConfig
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

function getTitleOptionByKey(TitleOptionsSettingsManager $manager, string $key): TitleOption
{
  foreach ($manager->getOptions() as $option) {
    if ($option->key === $key) {
      return $option;
    }
  }

  throw new RuntimeException("Option {$key} not found.");
}

it('updates and persists the title volume setting', function () {
  $filename = tempnam(sys_get_temp_dir(), 'ichiloto-title-options-');
  $config = new TitleProjectConfigPersistProxy([
    'filename' => $filename,
    'initial' => [
      'audio' => [
        'master_volume' => 75,
      ],
    ],
  ]);
  ConfigStore::put(AppConfig::class, new TitleInlineConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, $config);

  $manager = new TitleOptionsSettingsManager();
  $option = getTitleOptionByKey($manager, 'volume');
  $label = $manager->cycle($option, 1);

  expect($label)->toBe('80')
    ->and($config->get('audio.master_volume'))->toBe(80)
    ->and(file_get_contents($filename))->toContain("return [")
    ->and(file_get_contents($filename))->not->toContain('return array (')
    ->and(file_get_contents($filename))->toContain("'master_volume' => 80");

  unlink($filename);
});

it('clamps title option values instead of wrapping them', function () {
  $filename = tempnam(sys_get_temp_dir(), 'ichiloto-title-options-');
  $config = new TitleProjectConfigPersistProxy([
    'filename' => $filename,
    'initial' => [
      'audio' => [
        'master_volume' => 100,
        'music' => true,
      ],
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
  ConfigStore::put(AppConfig::class, new TitleInlineConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, $config);

  $manager = new TitleOptionsSettingsManager();

  $volumeOption = getTitleOptionByKey($manager, 'volume');
  $volumeLabel = $manager->cycle($volumeOption, 1);

  $textSpeedOption = getTitleOptionByKey($manager, 'text_speed');
  $textSpeedLabel = $manager->cycle($textSpeedOption, -1);

  expect($volumeLabel)->toBe('100')
    ->and($config->get('audio.master_volume'))->toBe(100)
    ->and($textSpeedLabel)->toBe('Slow')
    ->and($config->get('ui.dialogue.speed'))->toBe(20)
    ->and($config->get('ui.dialogue.message.speed'))->toBe(20);

  unlink($filename);
});

it('updates title text speed on both supported dialogue paths', function () {
  $filename = tempnam(sys_get_temp_dir(), 'ichiloto-title-options-');
  $config = new TitleProjectConfigPersistProxy([
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
  ConfigStore::put(AppConfig::class, new TitleInlineConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, $config);

  $manager = new TitleOptionsSettingsManager();
  $option = getTitleOptionByKey($manager, 'text_speed');
  $label = $manager->cycle($option, 1);

  expect($label)->toBe('Normal')
    ->and($config->get('ui.dialogue.speed'))->toBe(50)
    ->and($config->get('ui.dialogue.message.speed'))->toBe(50);

  unlink($filename);
});
