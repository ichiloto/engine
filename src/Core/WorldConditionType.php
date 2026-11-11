<?php

namespace Ichiloto\Engine\Core;

/**
 * The authoritative condition vocabulary shared by runtime and authoring.
 */
enum WorldConditionType: string
{
  case QUEST = 'quest';
  case SWITCH = 'switch';
  case EVENT = 'event';
  case ANY = 'any';
  case MAP_EVENT = 'map_event';
  case VARIABLE = 'variable';
  case PLAY_STATISTIC = 'play_statistic';
  case ITEM = 'item';
  case KEY_ITEM = 'key_item';
  case WORLD_ABILITY = 'world_ability';
  case ASSIGNED_SUMMONS = 'assigned_summons';
  case PRESENT_ACTOR = 'present_actor';
  case PRESENT_PARTY_SIZE = 'present_party_size';

  /**
   * Returns the stable wire values used in project data.
   *
   * @return string[]
   */
  public static function values(): array
  {
    return array_map(static fn(self $type): string => $type->value, self::cases());
  }
}
