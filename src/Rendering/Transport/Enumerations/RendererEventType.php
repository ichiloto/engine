<?php

namespace Ichiloto\Engine\Rendering\Transport\Enumerations;

enum RendererEventType: string
{
  case READY = 'ready';
  case KEY = 'key';
  /** Negotiated key_transitions only: a held control was released. */
  case KEY_RELEASE = 'key_release';
  /** Negotiated key_transitions only: the renderer no longer vouches for any held control. */
  case INPUT_RESET = 'input_reset';
  case CLOSE_REQUESTED = 'close_requested';
  case ERROR = 'error';
  case WINDOW_ACTIVATION = 'window_activation';
  case FRAME_ACK = 'frame_ack';
  case FRAME_REJECTED = 'frame_rejected';
  case RESIZED = 'resized';
}
