<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

/**
 * Describes a configurable option shown in the in-game config menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
readonly class MainMenuSetting
{
  /**
   * @param string $key The internal setting key.
   * @param string $label The label shown in the settings list.
   * @param string $description The explanatory text for the setting.
   * @param array<string, mixed> $choices The available choices keyed by display label.
   */
  public function __construct(
    public string $key,
    public string $label,
    public string $description,
    public array $choices,
  )
  {
  }
}
