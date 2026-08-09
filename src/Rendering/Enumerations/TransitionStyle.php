<?php

namespace Ichiloto\Engine\Rendering\Enumerations;

/**
 * How the screen is covered between two views.
 *
 * @package Ichiloto\Engine\Rendering\Enumerations
 */
enum TransitionStyle: string
{
  /**
   * No effect: the new view simply appears.
   */
  case NONE = 'none';
  /**
   * The screen fills with progressively heavier block shades.
   */
  case FADE = 'fade';
  /**
   * Solid columns sweep across the screen.
   */
  case WIPE = 'wipe';
}
