<?php

namespace Ichiloto\Engine\Rendering\Transport\Enumerations;

enum RendererEventType: string
{
  case READY = 'ready';
  case KEY = 'key';
  case CLOSE_REQUESTED = 'close_requested';
  case ERROR = 'error';
  case WINDOW_ACTIVATION = 'window_activation';
  case FRAME_ACK = 'frame_ack';
  case FRAME_REJECTED = 'frame_rejected';
  case RESIZED = 'resized';
}
