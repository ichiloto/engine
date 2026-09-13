<?php

namespace Ichiloto\Engine\Rendering\Transport;

enum RendererTransportState
{
  case NEW;
  case STARTING;
  case RUNNING;
  case STOPPING;
  case STOPPED;
  case FAILED;
}
