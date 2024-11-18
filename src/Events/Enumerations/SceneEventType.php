<?php

namespace Ichiloto\Engine\Events\Enumerations;


/**
 * Class SceneEventType. Represents a scene event type.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum SceneEventType
{
  case INIT;
  case START;
  case STOP;
  case LOAD_START;
  case LOAD_END;
  case UNLOAD;
  case RENDER;
  case RENDER_BACKGROUND;
  case RENDER_SPRITES;
  case ERASE;
  case UPDATE;
  case RESUME;
  case SUSPEND;
}
