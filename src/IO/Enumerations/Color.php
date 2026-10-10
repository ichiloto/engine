<?php

namespace Ichiloto\Engine\IO\Enumerations;

use Ichiloto\Engine\IO\Console\SgrColorParser;

/**
 * Represents a color.
 */
enum Color: string
{
  private const int BRIGHT_COLOR_OFFSET = 8;
  private const int DARK_BACKGROUND_BASE = 40;
  private const int BRIGHT_BACKGROUND_BASE = 100;
  private const int BLACK_FOREGROUND = 30;
  private const int BRIGHT_WHITE_FOREGROUND = 97;

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

  /** Returns a terminal background in this color with legible contrasting text. */
  public function getContrastingBackgroundSequence(): ?string
  {
    $foreground = SgrColorParser::parse($this->value)['foreground'];
    $index = $foreground?->toArray()['index'] ?? null;
    if (!is_int($index)) { return null; }

    $bright = $index >= self::BRIGHT_COLOR_OFFSET;
    $background = ($bright ? self::BRIGHT_BACKGROUND_BASE : self::DARK_BACKGROUND_BASE)
      + ($index % self::BRIGHT_COLOR_OFFSET);
    $contrast = $bright ? self::BLACK_FOREGROUND : self::BRIGHT_WHITE_FOREGROUND;

    return sprintf("\033[%d;%dm", $contrast, $background);
  }

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
