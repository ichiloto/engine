<?php

use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

class TitleOptionsConfigStub implements ConfigInterface
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
  }

  public function has(string $path): bool
  {
    return true;
  }

  public function persist(): void
  {
  }
}

/**
 * A project config that keeps its values in memory.
 */
class TitleOptionsProjectConfig extends ProjectConfig
{
  protected function load(): array
  {
    return $this->options['initial'] ?? [];
  }

  protected function getFilename(): string
  {
    return $this->options['filename'] ?? 'config.php';
  }

  public function persist(): void
  {
    // Kept in memory; the write path has its own tests.
  }
}

/**
 * Drives the title screen's options overlay without booting a game.
 */
class TitleOptionsProbe extends TitleScene
{
  public function __construct(array $options, TitleOptionsSettingsManager $manager, int $activeIndex)
  {
    $this->options = $options;
    $this->optionsManager = $manager;
    $this->activeOptionIndex = $activeIndex;
  }

  public function change(int $step): void
  {
    $this->changeActiveOption($step);
  }

  protected function renderOptionsMenu(): void
  {
    // Drawing needs windows and a terminal; the change itself does not.
  }
}

/**
 * Builds the probe positioned on a named setting.
 *
 * @param string $key The setting to select.
 * @return TitleOptionsProbe The probe.
 */
function titleOptionsProbeFor(string $key): TitleOptionsProbe
{
  $manager = new TitleOptionsSettingsManager();
  $options = $manager->getOptions();
  $index = 0;

  foreach ($options as $position => $option) {
    if ($option->key === $key) {
      $index = $position;
    }
  }

  return new TitleOptionsProbe($options, $manager, $index);
}

beforeEach(function () {
  ConfigStore::put(AppConfig::class, new TitleOptionsConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, new TitleOptionsProjectConfig([
    'filename' => 'config.php',
    'initial' => ['audio' => ['master_volume' => 50, 'music' => false]],
  ]));
});

it('changes a setting from the title screen', function () {
  $config = ConfigStore::get(ProjectConfig::class);

  titleOptionsProbeFor('volume')->change(1);

  // The overlay guards on the setting's type, and a guard naming a class that
  // does not resolve is silently false: the options render, the keys respond,
  // and nothing ever changes.
  expect($config->get('audio.master_volume'))->toBe(55);
});

it('changes a setting the other way', function () {
  $config = ConfigStore::get(ProjectConfig::class);

  titleOptionsProbeFor('volume')->change(-1);

  expect($config->get('audio.master_volume'))->toBe(45);
});

it('toggles a switch from the title screen', function () {
  $config = ConfigStore::get(ProjectConfig::class);

  titleOptionsProbeFor('music')->change(1);

  expect($config->get('audio.music'))->toBeTrue();
});

it('lists settings the overlay can actually act on', function () {
  $options = new TitleOptionsSettingsManager()->getOptions();

  expect($options)->not->toBeEmpty();

  foreach ($options as $option) {
    expect($option)->toBeInstanceOf(GameSetting::class);
  }
});
