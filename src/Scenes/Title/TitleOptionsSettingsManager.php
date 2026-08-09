<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\Settings\SettingsManager;

/**
 * The settings listed by the title-screen options overlay.
 *
 * @package Ichiloto\Engine\Scenes\Title
 */
class TitleOptionsSettingsManager extends SettingsManager
{
  /**
   * @inheritDoc
   */
  protected function settingKeys(): array
  {
    return [
      'volume',
      'cursor_memory',
      'music',
      'sfx',
      'dialogue_speed',
    ];
  }

  /**
   * Returns the options shown in the title menu.
   *
   * @return GameSetting[] The options.
   */
  public function getOptions(): array
  {
    return $this->getSettings();
  }

  /**
   * Returns whether cursor memory is currently enabled.
   *
   * @return bool True when cursor memory is enabled.
   */
  public function isCursorMemoryEnabled(): bool
  {
    return boolval($this->catalog->read('cursor_memory'));
  }
}
