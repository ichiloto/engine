<?php

namespace Ichiloto\Engine\Field\Reachability;

/**
 * What a reachability problem keeps the player from doing.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
enum ReachabilityProblemKind: string
{
  /** The map or its collisions could not be read. */
  case UNREADABLE_MAP = 'unreadable_map';
  /** An arrival names a map the project does not have. */
  case UNKNOWN_DESTINATION = 'unknown_destination';
  /**
   * The player would arrive outside the map, or on a solid or occupied cell
   * (they can step off it, but arrive overlapping it).
   */
  case BLOCKED_ENTRANCE = 'blocked_entrance';
  /** No arrival brings the player onto the map. */
  case NO_ENTRANCE = 'no_entrance';
  /** No cell of an event or edge trigger can be reached from the map's entrances. */
  case UNREACHABLE_EVENT = 'unreachable_event';
  /** A talkable NPC has no reachable cell beside it to be spoken to from. */
  case UNREACHABLE_NPC = 'unreachable_npc';
}
