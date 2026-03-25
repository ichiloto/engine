<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use RuntimeException;

/**
 * Resolves and persists the title-screen options menu settings.
 *
 * @package Ichiloto\Engine\Scenes\Title
 */
class TitleOptionsSettingsManager
{
  protected const int MIN_VOLUME = 0;
  protected const int MAX_VOLUME = 100;
  protected const int VOLUME_STEP = 5;

  /**
   * Returns the title-screen options.
   *
   * @return TitleOption[] The options shown in the title menu.
   */
  public function getOptions(): array
  {
    return [
      new TitleOption(
        'volume',
        'Volume',
        $this->buildVolumeChoices()
      ),
      new TitleOption(
        'cursor_memory',
        'Cursor Memory',
        [
          'Off' => false,
          'On' => true,
        ]
      ),
      new TitleOption(
        'music',
        'Music',
        [
          'Off' => false,
          'On' => true,
        ]
      ),
      new TitleOption(
        'sfx',
        'SFX',
        [
          'Off' => false,
          'On' => true,
        ]
      ),
      new TitleOption(
        'text_speed',
        'Text Speed',
        [
          'Slow' => 20,
          'Normal' => 50,
          'Fast' => 80,
        ]
      ),
    ];
  }

  /**
   * Returns the current display label for the option.
   *
   * @param TitleOption $option The option to inspect.
   * @return string The active label.
   */
  public function getCurrentChoiceLabel(TitleOption $option): string
  {
    $labels = array_keys($option->choices);
    $index = $this->getCurrentChoiceIndex($option);

    return $labels[$index] ?? $labels[0] ?? '';
  }

  /**
   * Returns the current choice index for the option.
   *
   * @param TitleOption $option The option to inspect.
   * @return int The resolved choice index.
   */
  public function getCurrentChoiceIndex(TitleOption $option): int
  {
    $value = $this->getSettingValue($option->key);
    $choices = array_values($option->choices);
    $index = array_search($value, $choices, true);

    return is_int($index) ? $index : 0;
  }

  /**
   * Returns the list of available display labels for the option.
   *
   * @param TitleOption $option The option to inspect.
   * @return string[] The available display labels.
   */
  public function getChoiceLabels(TitleOption $option): array
  {
    return array_keys($option->choices);
  }

  /**
   * Cycles the option to the next or previous value and persists it.
   *
   * @param TitleOption $option The option to change.
   * @param int $step The direction to move. Use `1` for next and `-1` for previous.
   * @return string The display label of the new choice.
   */
  public function cycle(TitleOption $option, int $step): string
  {
    $choices = array_values($option->choices);
    $labels = array_keys($option->choices);
    $nextIndex = clamp($this->getCurrentChoiceIndex($option) + $step, 0, count($choices) - 1);

    $this->applySettingValue($option->key, $choices[$nextIndex]);
    $this->persist();

    return $labels[$nextIndex];
  }

  /**
   * Returns whether cursor memory is currently enabled.
   *
   * @return bool True when cursor memory is enabled.
   */
  public function isCursorMemoryEnabled(): bool
  {
    return boolval($this->getSettingValue('cursor_memory'));
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
      'volume' => config(ProjectConfig::class, 'audio.master_volume', 75),
      'cursor_memory' => config(ProjectConfig::class, 'ui.cursor.memory', false),
      'music' => config(ProjectConfig::class, 'audio.music', false),
      'sfx' => config(ProjectConfig::class, 'audio.sfx', false),
      'text_speed' => config(
        ProjectConfig::class,
        'ui.dialogue.speed',
        config(ProjectConfig::class, 'ui.dialogue.message.speed', 20)
      ),
      default => null,
    };
  }

  /**
   * Applies the selected value to the project config.
   *
   * @param string $key The internal setting key.
   * @param mixed $value The selected value.
   * @return void
   */
  protected function applySettingValue(string $key, mixed $value): void
  {
    $config = ConfigStore::get(ProjectConfig::class);

    match ($key) {
      'volume' => $config->set('audio.master_volume', intval($value)),
      'cursor_memory' => $config->set('ui.cursor.memory', boolval($value)),
      'music' => $config->set('audio.music', boolval($value)),
      'sfx' => $config->set('audio.sfx', boolval($value)),
      'text_speed' => $this->applyTextSpeed($config, intval($value)),
      default => null,
    };
  }

  /**
   * Applies the selected text-speed value to both supported dialogue paths.
   *
   * @param object $config The project config.
   * @param int $value The selected text-speed value.
   * @return void
   */
  protected function applyTextSpeed(object $config, int $value): void
  {
    $config->set('ui.dialogue.speed', $value);
    $config->set('ui.dialogue.message.speed', $value);
  }

  /**
   * Builds the volume choices shown in the title options overlay.
   *
   * @return array<string, int> The available volume steps.
   */
  protected function buildVolumeChoices(): array
  {
    $choices = [];

    for ($volume = self::MIN_VOLUME; $volume <= self::MAX_VOLUME; $volume += self::VOLUME_STEP) {
      $choices[(string)$volume] = $volume;
    }

    return $choices;
  }

  /**
   * Persists the current project configuration.
   *
   * @return void
   */
  protected function persist(): void
  {
    $config = ConfigStore::get(ProjectConfig::class);

    if (! $config instanceof ProjectConfig) {
      throw new RuntimeException('Project config is not available.');
    }

    $config->persist();
  }
}
