<?php

namespace Ichiloto\Engine\Rendering\Transport;

/** Wire vocabulary only; no frame generation or graphics models. */
enum RendererMessageType: string
{
  case HELLO = 'hello';
  case FRAME = 'frame';
  case SHUTDOWN = 'shutdown';
}
