<?php

namespace Ichiloto\Engine\Settings;

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use RuntimeException;

/**
 * Every configurable setting, defined once.
 *
 * A setting's choices, its config paths, and how a chosen value is applied
 * all live here, so the title options overlay and the in-game config menu
 * cannot drift apart: adjusting volume in one place means exactly what it
 * means in the other.
 *
 * @package Ichiloto\Engine\Settings
 */
class SettingsCatalog
{
  /**
   * The master volume bounds and granularity.
   */
  protected const int MIN_VOLUME = 0;
  protected const int MAX_VOLUME = 100;
  protected const int VOLUME_STEP = 5;

  /**
   * Returns every defined setting, keyed by its setting key.
   *
   * @return array<string, GameSetting> The settings.
   */
  public function all(): array
  {
    $settings = [
      new GameSetting(
        'volume',
        'Volume',
        'Sets the master volume for music and sound effects.',
        $this->buildVolumeChoices(),
        wraps: false,
      ),
      new GameSetting(
        'music',
        'Music',
        'Turns background music on or off.',
        ['Off' => false, 'On' => true],
      ),
      new GameSetting(
        'sfx',
        'SFX',
        'Turns sound effects on or off.',
        ['Off' => false, 'On' => true],
      ),
      new GameSetting(
        'dialogue_speed',
        'Text Speed',
        'Controls how quickly dialogue text appears on screen.',
        ['Slow' => 20, 'Normal' => 50, 'Fast' => 80],
      ),
      new GameSetting(
        'cursor_memory',
        'Cursor Memory',
        'Reopens a menu on the entry it was last left on.',
        ['Off' => false, 'On' => true],
      ),
      new GameSetting(
        'battle_message_pace',
        'Battle Message Pace',
        'Controls how long battle messages stay visible.',
        ['Slow' => 'slow', 'Medium' => 'medium', 'Fast' => 'fast'],
      ),
      new GameSetting(
        'battle_animation_pace',
        'Battle Animation Pace',
        'Controls the overall timing of battle action sequences.',
        ['Slow' => 'slow', 'Medium' => 'medium', 'Fast' => 'fast'],
      ),
      new GameSetting(
        'selection_color',
        'Selection Color',
        'Changes the highlight color used by menus, dialogs, and battle input.',
        [
          'Light Blue' => Color::LIGHT_BLUE,
          'Yellow' => Color::YELLOW,
          'Light Green' => Color::LIGHT_GREEN,
          'Light Cyan' => Color::LIGHT_CYAN,
          'White' => Color::WHITE,
        ],
      ),
      new GameSetting(
        'transitions',
        'Screen Transitions',
        'Plays an effect when moving between places, instead of cutting straight there.',
        [
          'Off' => TransitionStyle::NONE,
          'Fade' => TransitionStyle::FADE,
          'Wipe' => TransitionStyle::WIPE,
        ],
      ),
      new GameSetting(
        'location_hud',
        'Location HUD',
        'Toggles the field HUD that shows coordinates and facing direction.',
        ['Off' => false, 'On' => true],
      ),
    ];

    return array_combine(
      array_map(static fn(GameSetting $setting): string => $setting->key, $settings),
      $settings
    );
  }

  /**
   * Returns the named settings, in the order asked for.
   *
   * Unknown keys are skipped rather than fatal, so a surface listing a
   * setting a project has not enabled simply shows one entry fewer.
   *
   * @param string ...$keys The setting keys to resolve.
   * @return GameSetting[] The resolved settings.
   */
  public function select(string ...$keys): array
  {
    $all = $this->all();

    return array_values(array_filter(array_map(
      static fn(string $key): ?GameSetting => $all[$key] ?? null,
      $keys
    )));
  }

  /**
   * Returns the active value for a setting.
   *
   * @param string $key The setting key.
   * @return mixed The current value.
   */
  public function read(string $key): mixed
  {
    return match ($key) {
      'volume' => $this->snapVolumeToStep(
        intval(config(ProjectConfig::class, 'audio.master_volume', 75))
      ),
      'music' => boolval(config(ProjectConfig::class, 'audio.music', false)),
      'sfx' => boolval(config(ProjectConfig::class, 'audio.sfx', false)),
      'dialogue_speed' => config(
        ProjectConfig::class,
        'ui.dialogue.speed',
        config(ProjectConfig::class, 'ui.dialogue.message.speed', 50)
      ),
      'cursor_memory' => boolval(config(ProjectConfig::class, 'ui.cursor.memory', false)),
      'battle_message_pace' => config(ProjectConfig::class, 'ui.battle.message_pace', 'slow'),
      'battle_animation_pace' => config(
        ProjectConfig::class,
        'ui.battle.animation_pace',
        config(ProjectConfig::class, 'ui.battle.message_pace', 'slow')
      ),
      'selection_color' => config(
        ProjectConfig::class,
        'ui.menu.selection_color',
        config(ProjectConfig::class, 'ui.battle.selection_color', Color::LIGHT_BLUE)
      ),
      'location_hud' => boolval(config(ProjectConfig::class, 'ui.hud.location', false)),
      'transitions' => TransitionStyle::tryFrom(strtolower(strval(
        config(ProjectConfig::class, ScreenTransition::CONFIG_STYLE, TransitionStyle::NONE->value)
      ))) ?? TransitionStyle::NONE,
      default => null,
    };
  }

  /**
   * Applies a chosen value to the config paths the setting owns.
   *
   * @param string $key The setting key.
   * @param mixed $value The chosen value.
   * @return void
   */
  public function write(string $key, mixed $value): void
  {
    $config = ConfigStore::get(ProjectConfig::class);

    match ($key) {
      'volume' => $config->set('audio.master_volume', intval($value)),
      'music' => $config->set('audio.music', boolval($value)),
      'sfx' => $config->set('audio.sfx', boolval($value)),
      // Two paths, because dialogue speed was authored under both and a
      // project may read either.
      'dialogue_speed' => $this->writeBoth($config, ['ui.dialogue.speed', 'ui.dialogue.message.speed'], intval($value)),
      'cursor_memory' => $config->set('ui.cursor.memory', boolval($value)),
      'battle_message_pace' => $config->set('ui.battle.message_pace', strval($value)),
      'battle_animation_pace' => $config->set('ui.battle.animation_pace', strval($value)),
      'selection_color' => $this->writeBoth(
        $config,
        ['ui.menu.selection_color', 'ui.battle.selection_color'],
        $value instanceof Color ? $value : Color::LIGHT_BLUE
      ),
      'location_hud' => $config->set('ui.hud.location', boolval($value)),
      'transitions' => $config->set(
        ScreenTransition::CONFIG_STYLE,
        ($value instanceof TransitionStyle ? $value : TransitionStyle::NONE)->value
      ),
      default => null,
    };
  }

  /**
   * Persists the project configuration to disk.
   *
   * @return void
   */
  public function persist(): void
  {
    $config = ConfigStore::get(ProjectConfig::class);

    if (! $config instanceof ProjectConfig) {
      throw new RuntimeException('Project config is not available.');
    }

    $config->persist();
  }

  /**
   * Writes one value to several config paths.
   *
   * @param object $config The project config.
   * @param string[] $paths The config paths to write.
   * @param mixed $value The value to write.
   * @return void
   */
  protected function writeBoth(object $config, array $paths, mixed $value): void
  {
    foreach ($paths as $path) {
      $config->set($path, $value);
    }
  }

  /**
   * Builds the selectable master volume steps, labelled as percentages.
   *
   * @return array<string, int> The volume choices keyed by display label.
   */
  protected function buildVolumeChoices(): array
  {
    $choices = [];

    for ($volume = self::MIN_VOLUME; $volume <= self::MAX_VOLUME; $volume += self::VOLUME_STEP) {
      $choices["{$volume}%"] = $volume;
    }

    return $choices;
  }

  /**
   * Snaps a stored volume to the nearest selectable step.
   *
   * A project may ship any volume it likes, and a value that is not one of
   * the steps would otherwise match no choice and show as the first one
   * (silence).
   *
   * @param int $volume The stored volume.
   * @return int The nearest selectable volume.
   */
  protected function snapVolumeToStep(int $volume): int
  {
    $clamped = clamp($volume, self::MIN_VOLUME, self::MAX_VOLUME);

    return (int)(round($clamped / self::VOLUME_STEP) * self::VOLUME_STEP);
  }
}
