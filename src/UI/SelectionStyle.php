<?php

namespace Ichiloto\Engine\UI;

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Applies consistent highlighting to active menu selections outside battle.
 *
 * @package Ichiloto\Engine\UI
 */
final class SelectionStyle
{
  /**
   * SelectionStyle constructor.
   */
  private function __construct()
  {
  }

  /**
   * Applies the configured selection highlight to a line of text.
   *
   * @param string $text The text to style.
   * @param bool $blink Whether the text should blink.
   * @return string The styled text.
   */
  public static function apply(string $text, bool $blink = false): string
  {
    $prefix = $blink ? "\033[5m" : '';

    return $prefix . self::resolveColor()->value . $text . Color::RESET->value;
  }

  /**
   * Resolves the configured selection color for menu-like interfaces.
   *
   * @return Color The resolved selection color.
   */
  public static function resolveColor(): Color
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return Color::LIGHT_BLUE;
    }

    $configuredColor = config(
      ProjectConfig::class,
      'ui.menu.selection_color',
      config(ProjectConfig::class, 'ui.battle.selection_color', Color::LIGHT_BLUE)
    );

    if ($configuredColor instanceof Color) {
      return $configuredColor;
    }

    if (is_string($configuredColor)) {
      $normalizedName = strtoupper(str_replace([' ', '-'], '_', $configuredColor));

      foreach (Color::cases() as $color) {
        if ($color->name === $normalizedName || $color->value === $configuredColor) {
          return $color;
        }
      }
    }

    return Color::LIGHT_BLUE;
  }
}
