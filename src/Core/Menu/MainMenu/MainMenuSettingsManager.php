<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use RuntimeException;

/**
 * Resolves, mutates, and persists the configurable main-menu settings.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
class MainMenuSettingsManager
{
  /**
   * Returns the settings shown in the config menu.
   *
   * @return MainMenuSetting[] The available settings.
   */
  public function getSettings(): array
  {
    return [
      new MainMenuSetting(
        'dialogue_speed',
        'Dialogue Speed',
        'Controls how quickly dialogue text appears on screen.',
        [
          'Slow' => 20,
          'Normal' => 50,
          'Fast' => 80,
        ]
      ),
      new MainMenuSetting(
        'battle_message_pace',
        'Battle Message Pace',
        'Controls how long battle messages stay visible.',
        [
          'Fast' => 'fast',
          'Medium' => 'medium',
          'Slow' => 'slow',
        ]
      ),
      new MainMenuSetting(
        'battle_animation_pace',
        'Battle Animation Pace',
        'Controls the overall timing of battle action sequences.',
        [
          'Fast' => 'fast',
          'Medium' => 'medium',
          'Slow' => 'slow',
        ]
      ),
      new MainMenuSetting(
        'selection_color',
        'Selection Color',
        'Changes the highlight color used by menus, dialogs, and battle input.',
        [
          'Light Blue' => Color::LIGHT_BLUE,
          'Yellow' => Color::YELLOW,
          'Light Green' => Color::LIGHT_GREEN,
          'Light Cyan' => Color::LIGHT_CYAN,
          'White' => Color::WHITE,
        ]
      ),
      new MainMenuSetting(
        'location_hud',
        'Location HUD',
        'Toggles the field HUD that shows coordinates and facing direction.',
        [
          'Off' => false,
          'On' => true,
        ]
      ),
    ];
  }

  /**
   * Returns the display label for the setting's current value.
   *
   * @param MainMenuSetting $setting The setting to inspect.
   * @return string The current display label.
   */
  public function getCurrentChoiceLabel(MainMenuSetting $setting): string
  {
    $choices = array_keys($setting->choices);
    $index = $this->getCurrentChoiceIndex($setting);

    return $choices[$index] ?? $choices[0] ?? '';
  }

  /**
   * Returns the current choice index for the given setting.
   *
   * @param MainMenuSetting $setting The setting to inspect.
   * @return int The resolved choice index.
   */
  public function getCurrentChoiceIndex(MainMenuSetting $setting): int
  {
    $value = $this->getSettingValue($setting->key);
    $choices = array_values($setting->choices);
    $index = array_search($value, $choices, true);

    return is_int($index) ? $index : 0;
  }

  /**
   * Returns the list of display labels for the setting.
   *
   * @param MainMenuSetting $setting The setting to inspect.
   * @return string[] The available display labels.
   */
  public function getChoiceLabels(MainMenuSetting $setting): array
  {
    return array_keys($setting->choices);
  }

  /**
   * Cycles the setting to the next or previous choice and persists it.
   *
   * @param MainMenuSetting $setting The setting to change.
   * @param int $step The direction to move. Use `1` for next and `-1` for previous.
   * @return string The display label of the new choice.
   */
  public function cycle(MainMenuSetting $setting, int $step): string
  {
    $choices = array_values($setting->choices);
    $labels = array_keys($setting->choices);
    $nextIndex = wrap($this->getCurrentChoiceIndex($setting) + $step, 0, count($choices) - 1);

    $this->applySettingValue($setting->key, $choices[$nextIndex]);
    $this->persist();

    return $labels[$nextIndex];
  }

  /**
   * Persists the current project configuration to disk.
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
   * Returns the active value for the specified setting key.
   *
   * @param string $key The internal setting key.
   * @return mixed The current value.
   */
  protected function getSettingValue(string $key): mixed
  {
    return match ($key) {
      'dialogue_speed' => config(
        ProjectConfig::class,
        'ui.dialogue.speed',
        config(ProjectConfig::class, 'ui.dialogue.message.speed', 50)
      ),
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
      'location_hud' => config(ProjectConfig::class, 'ui.hud.location', false),
      default => null,
    };
  }

  /**
   * Applies the specified value to the relevant config path or paths.
   *
   * @param string $key The internal setting key.
   * @param mixed $value The value to apply.
   * @return void
   */
  protected function applySettingValue(string $key, mixed $value): void
  {
    $config = ConfigStore::get(ProjectConfig::class);

    match ($key) {
      'dialogue_speed' => $this->applyDialogueSpeedSetting($config, intval($value)),
      'battle_message_pace' => $config->set('ui.battle.message_pace', strval($value)),
      'battle_animation_pace' => $config->set('ui.battle.animation_pace', strval($value)),
      'selection_color' => $this->applySelectionColorSetting($config, $value),
      'location_hud' => $config->set('ui.hud.location', boolval($value)),
      default => null,
    };
  }

  /**
   * Applies the selected dialogue speed to both supported config paths.
   *
   * @param object $config The config object.
   * @param int $value The speed value.
   * @return void
   */
  protected function applyDialogueSpeedSetting(object $config, int $value): void
  {
    $config->set('ui.dialogue.speed', $value);
    $config->set('ui.dialogue.message.speed', $value);
  }

  /**
   * Applies the selected highlight color to menu and battle input.
   *
   * @param object $config The config object.
   * @param mixed $value The selected color value.
   * @return void
   */
  protected function applySelectionColorSetting(object $config, mixed $value): void
  {
    $color = $value instanceof Color ? $value : Color::LIGHT_BLUE;
    $config->set('ui.menu.selection_color', $color);
    $config->set('ui.battle.selection_color', $color);
  }
}
