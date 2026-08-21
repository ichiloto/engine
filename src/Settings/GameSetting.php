<?php

namespace Ichiloto\Engine\Settings;

/**
 * A configurable setting, as shown on a settings surface.
 *
 * The same setting is shown by more than one surface (the title options
 * overlay and the in-game config menu both offer volume), so a setting is
 * defined once in the {@see SettingsCatalog} and surfaces choose which ones
 * they list.
 *
 * @package Ichiloto\Engine\Settings
 */
readonly class GameSetting
{
  /**
   * @param string $key The internal setting key.
   * @param string $label The label shown in the settings list.
   * @param string $description The explanatory text shown alongside it.
   * @param array<string, mixed> $choices The available choices keyed by display label.
   * @param bool $wraps Whether cycling past the last choice returns to the
   * first. True suits a short list of alternatives; a long ordered scale
   * (volume) stops at its ends instead of jumping from loudest to silent.
   */
  public function __construct(
    public string $key,
    public string $label,
    public string $description = '',
    public array $choices = [],
    public bool $wraps = true,
  )
  {
  }
}
