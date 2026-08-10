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
  case VARIABLE = 'variable';
  case ITEM = 'item';
  case KEY_ITEM = 'key_item';

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
