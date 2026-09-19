<?php

namespace Ichiloto\Engine\Rendering\Transport\Enumerations;

enum RendererEventType: string
{
  case READY = 'ready';
  case KEY = 'key';
  case CLOSE_REQUESTED = 'close_requested';
  case ERROR = 'error';
}
