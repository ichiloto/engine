<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
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
  private Game $testGame;

  public function __construct(array $options, TitleOptionsSettingsManager $manager, int $activeIndex)
  {
    $this->options = $options;
    $this->optionsManager = $manager;
    $this->activeOptionIndex = $activeIndex;
    $this->initializeOptionsWindow();
    $this->showingOptions = true;
  }

  public function change(int $step): void
  {
    $this->changeActiveOption($step);
  }

  public function enableGraphicalPresentation(): void
  {
    $root = dirname(__DIR__) . '/Fixtures/Renderer';
    $this->testGame = new class extends Game {
      public function __construct() {}
      public function __destruct() {}
    };
    $this->testGame->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(
      new RendererProcessConfig(['/never-started']), $root,
    )));
    $this->menu = new TitleMenu($this, 'Title');
    $this->resetTitlePresentation();
    $catalog = new TitlePresentationCatalog($root, [
      'schema' => 'ichiloto.title/1', 'logo' => 'test-sprite.png',
      'theme' => ['schema' => 'ichiloto.menu/1', 'showInputHints' => false],
      'scenes' => ['day' => ['background' => 'test-sprite.png'], 'night' => ['background' => 'test-sprite.png']],
    ]);
    new ReflectionProperty(TitleScene::class, 'titleCatalog')->setValue($this, $catalog);
    new ReflectionProperty(TitleScene::class, 'titleCatalogLoaded')->setValue($this, true);
  }

  public function getGame(): Game
  {
    return $this->testGame;
  }

  public function getDrawnText(bool $graphical): string
  {
    if (!$graphical) {
      return TerminalText::stripAnsi(implode("\n", Console::getBuffer()));
    }
    $canvas = $this->getPresentationCanvas();
    expect($canvas)->not->toBeNull();
    return implode("\n", array_map(static fn($layer) => implode('', array_map(static fn($run) =>
      $run->foreground === null ? '' : $run->text, $layer->runs)), $canvas->textLayers));
  }
}

/**
 * Builds the probe positioned on a named setting.
 *
 * @param string $key The setting to select.
 * @return TitleOptionsProbe The probe.
 */
function titleOptionsProbeFor(string $key, ?TitleOptionsSettingsManager $manager = null): TitleOptionsProbe
{
  $manager ??= new TitleOptionsSettingsManager();
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
  $this->statics = [];
  foreach ([Console::class, ConfigStore::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(AppConfig::class, new TitleOptionsConfigStub(['debug' => ['file' => false]]));
  ConfigStore::put(ProjectConfig::class, new TitleOptionsProjectConfig([
    'filename' => 'config.php',
    'initial' => ['audio' => ['master_volume' => 50, 'music' => false]],
  ]));
});

afterEach(function () {
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
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

it('logs title option save failures while showing a plain session-only status', function (bool $graphical) {
  $root = sys_get_temp_dir() . '/ichiloto-title-status-' . bin2hex(random_bytes(5));
  mkdir($root);
  $previousDebug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $root]);
  try {
    $manager = new class extends TitleOptionsSettingsManager {
      public bool $rejectWrite = true;

      public function getOptions(): array
      {
        return array_map(static fn(GameSetting $setting) => new GameSetting(
          $setting->key, $setting->label, str_repeat('Long authored description. ', 30), $setting->choices, $setting->wraps,
        ), parent::getOptions());
      }

      public function cycle(GameSetting $setting, int $step): string
      {
        ConfigStore::get(ProjectConfig::class)->set('audio.master_volume', 55);
        if ($this->rejectWrite) { throw new RuntimeException('Permission denied at /private/path'); }
        return '55%';
      }
    };
    $probe = titleOptionsProbeFor('volume', $manager);
    if ($graphical) { $probe->enableGraphicalPresentation(); }
    $probe->change(1);
    $drawn = $probe->getDrawnText($graphical);
    expect($drawn)->toContain('Could not save settings.', 'for this session.', '55%')
      ->not->toContain('/private/path', 'Permission denied', 'Long authored description.')
      ->and(ConfigStore::get(ProjectConfig::class)->get('audio.master_volume'))->toBe(55)
      ->and(file_get_contents($root . '/warning.log'))->toContain('Permission denied at /private/path');
    $manager->rejectWrite = false;
    $probe->change(1);
    expect($probe->getDrawnText($graphical))->not->toContain('Could not save settings.');
    if ($graphical) { expect($probe->getDrawnText(true))->toContain('Long authored description.'); }
  } finally {
    foreach ($previousDebug as $name => $value) {
      new ReflectionProperty(Debug::class, $name)->setValue(null, $value);
    }
    if (is_file($root . '/warning.log')) { unlink($root . '/warning.log'); }
    if (is_file($root . '/debug.log')) { unlink($root . '/debug.log'); }
    rmdir($root);
  }
})->with(['terminal' => false, 'graphical' => true]);

it('lists settings the overlay can actually act on', function () {
  $options = new TitleOptionsSettingsManager()->getOptions();

  expect($options)->not->toBeEmpty();

  foreach ($options as $option) {
    expect($option)->toBeInstanceOf(GameSetting::class);
  }
});
