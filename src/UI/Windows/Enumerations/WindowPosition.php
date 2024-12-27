<?php

namespace Ichiloto\Engine\UI\Windows\Enumerations;

use Ichiloto\Engine\Core\Vector2;

/**
 * Enumerates the possible window positions.
 *
 * @package Ichiloto\Engine\UI\Windows\Enumerations
 */
enum WindowPosition
{
  case TOP;
  case MIDDLE;
  case BOTTOM;

  /**
   * Gets the coordinates of the window.
   *
   * @param int $windowWidth The width of the window.
   * @param int $windowHeight The height of the window.
   *
   * @return Vector2 Returns the coordinates of the window.
   */
  public function getCoordinates(int $windowWidth, int $windowHeight): Vector2
  {
    $leftMargin = (int)( (get_screen_width() / 2) - ($windowWidth / 2) );
    $middleAlignedTopMargin = (int)( (get_screen_height() / 2) - ($windowHeight / 2) );
    $bottomAlignedTopMargin = get_screen_height() - $windowHeight;

    return match ($this) {
      self::TOP     => new Vector2($leftMargin, 0),
      self::MIDDLE  => new Vector2($leftMargin, $middleAlignedTopMargin),
      self::BOTTOM  => new Vector2($leftMargin, $bottomAlignedTopMargin),
    };
  }
}
