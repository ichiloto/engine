<?php

namespace Ichiloto\Engine\UI\Windows;

use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;

/**
 * WindowAlignment is an enumeration of all window alignment types.
 */
readonly class WindowAlignment
{
  /**
   * Creates a new WindowAlignment instance.
   *
   * @param HorizontalAlignment $horizontalAlignment The horizontal alignment.
   * @param VerticalAlignment $verticalAlignment The vertical alignment.
   */
  public function __construct(
    public HorizontalAlignment $horizontalAlignment,
    public VerticalAlignment $verticalAlignment
  )
  {
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the top left.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the top left.
   */
  public static function topLeft(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::TOP);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the top center.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the top center.
   */
  public static function topCenter(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::CENTER, VerticalAlignment::TOP);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the top right.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the top right.
   */
  public static function topRight(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::RIGHT, VerticalAlignment::TOP);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the middle left.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the middle left.
   */
  public static function middleLeft(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::MIDDLE);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the middle center.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the middle center.
   */
  public static function middleCenter(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::CENTER, VerticalAlignment::MIDDLE);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the middle right.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the middle right.
   */
  public static function middleRight(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::RIGHT, VerticalAlignment::MIDDLE);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the bottom left.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the bottom left.
   */
  public static function bottomLeft(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::BOTTOM);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the bottom center.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the bottom center.
   */
  public static function bottomCenter(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::CENTER, VerticalAlignment::BOTTOM);
  }

  /**
   * Returns a WindowAlignment instance that aligns a window to the bottom right.
   *
   * @return WindowAlignment A WindowAlignment instance that aligns a window to the bottom right.
   */
  public static function bottomRight(): WindowAlignment
  {
    return new WindowAlignment(HorizontalAlignment::RIGHT, VerticalAlignment::BOTTOM);
  }
}