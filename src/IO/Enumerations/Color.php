<?php

namespace Ichiloto\Engine\IO\Enumerations;

/**
 * Represents a color.
 */
enum Color: string
{
  case BLACK = "\033[0;30m";
  case DARK_GRAY = "\033[1;30m";
  case BLUE = "\033[0;34m";
  case LIGHT_BLUE = "\033[1;34m";
  case GREEN = "\033[0;32m";
  case LIGHT_GREEN = "\033[1;32m";
  case CYAN = "\033[0;36m";
  case LIGHT_CYAN = "\033[1;36m";
  case RED = "\033[0;31m";
  case LIGHT_RED = "\033[1;31m";
  case PURPLE = "\033[0;35m";
  case LIGHT_PURPLE = "\033[1;35m";
  case BROWN = "\033[0;33m";
  case YELLOW = "\033[1;33m";
  case LIGHT_GRAY = "\033[0;37m";
  case WHITE = "\033[1;37m";
  case WHITE_BLINK = "\033[5;37m";
  case RESET = "\033[0m";

  /**
   * Applies the color to the given string.
   *
   * @param mixed $string The string to apply the color to.
   * @param Color $color The color to apply.
   * @return string
   */
  public static function apply(string $string, Color $color): string
  {
    return $color->value . $string . Color::RESET->value;
  }
}