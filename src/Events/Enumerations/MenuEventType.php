<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * Class MenuEventType. Represents a menu event type.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum MenuEventType
{
  case UPDATE;
  case UPDATE_CONTENT;
  case RENDER;
  case ERASE;
  case ITEM_ACTIVATED;
  case ITEM_SELECTED;
  case ITEM_ADDED;
  case ITEM_REMOVED;
  case ITEMS_SET;
  case ITEM_RECEIVED_HORIZONTAL_INPUT;
  case ITEM_RECEIVED_VERTICAL_INPUT;
}
