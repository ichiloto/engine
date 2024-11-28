<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * Class ModalEventType. Represents the type of modal event.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum ModalEventType
{
  case SHOW;
  case HIDE;
  case UPDATE;
  case RENDER;
  case ACTION;
  case OPEN;
  case CLOSE;
  case CONFIRM;
  case CANCEL;
}
