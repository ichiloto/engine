<?php

namespace Ichiloto\Engine\Settings;

/**
 * Reads, cycles, and persists settings for a settings surface.
 *
 * Subclasses only declare which settings their surface lists; everything
 * else, what a setting currently reads as, what cycling it does, and where
 * the value lands, comes from the shared {@see SettingsCatalog}.
 *
 * @package Ichiloto\Engine\Settings
 */
abstract class SettingsManager
{
  /**
   * @param SettingsCatalog $catalog The catalog backing every setting.
   */
  public function __construct(
    protected SettingsCatalog $catalog = new SettingsCatalog(),
  )
  {
  }

  /**
   * Returns the setting keys this surface lists, in display order.
   *
   * @return string[] The setting keys.
   */
  abstract protected function settingKeys(): array;

  /**
   * Returns the settings shown on this surface.
   *
   * @return GameSetting[] The settings.
   */
  public function getSettings(): array
  {
    return $this->catalog->select(...$this->settingKeys());
  }

  /**
   * Returns the display label for the setting's current value.
   *
   * @param GameSetting $setting The setting to inspect.
   * @return string The current display label.
   */
  public function getCurrentChoiceLabel(GameSetting $setting): string
  {
    $labels = array_keys($setting->choices);

    return $labels[$this->getCurrentChoiceIndex($setting)] ?? $labels[0] ?? '';
  }

  /**
   * Returns the current choice index for the setting.
   *
   * @param GameSetting $setting The setting to inspect.
   * @return int The resolved choice index.
   */
  public function getCurrentChoiceIndex(GameSetting $setting): int
  {
    $index = array_search($this->catalog->read($setting->key), array_values($setting->choices), true);

    return is_int($index) ? $index : 0;
  }

  /**
   * Returns the display labels for the setting's choices.
   *
   * @param GameSetting $setting The setting to inspect.
   * @return string[] The available display labels.
   */
  public function getChoiceLabels(GameSetting $setting): array
  {
    return array_keys($setting->choices);
  }

  /**
   * Cycles the setting to the next or previous choice and persists it.
   *
   * @param GameSetting $setting The setting to change.
   * @param int $step The direction to move. Use `1` for next and `-1` for previous.
   * @return string The display label of the new choice.
   */
  public function cycle(GameSetting $setting, int $step): string
  {
    $choices = array_values($setting->choices);
    $labels = array_keys($setting->choices);
    $lastIndex = count($choices) - 1;
    $target = $this->getCurrentChoiceIndex($setting) + $step;
    $nextIndex = $setting->wraps ? wrap($target, 0, $lastIndex) : clamp($target, 0, $lastIndex);

    $this->catalog->write($setting->key, $choices[$nextIndex]);
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
    $this->catalog->persist();
  }
}
