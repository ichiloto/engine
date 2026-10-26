<?php

namespace Ichiloto\Engine\Rendering\Transport\Enumerations;

/** Wire vocabulary only; no frame generation or graphics models. */
enum RendererMessageType: string
{
  case HELLO = 'hello';
  case FRAME = 'frame';
  case SHUTDOWN = 'shutdown';
  case PREPARE_TEXT = 'prepare_text';
  case RELEASE_TEXT = 'release_text';
}
