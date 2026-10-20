<?php

namespace Ichiloto\Engine\Core;

/**
 * What the player is doing this frame, as far as active play time is
 * concerned. Scenes report it; time-based systems decide what counts.
 *
 * @package Ichiloto\Engine\Core
 */
enum PlayActivity: string
{
  /** The player is in control: roaming, talking, choosing battle commands. Idle time can be excluded. */
  case PLAYING = 'playing';
  /** The game is presenting without the player: cutscenes, running events, battle openings and results. */
  case PRESENTING = 'presenting';
  /** Menus, shops, saving, map views, pauses, title and other screens outside play. */
  case PAUSED = 'paused';

  /**
   * The most one frame may contribute. A longer frame means the loop was
   * blocked (a modal, a suspended machine), and that time is not play.
   */
  public const float MAX_FRAME_SECONDS = 0.5;
}
