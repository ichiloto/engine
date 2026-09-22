<?php

namespace Ichiloto\Engine\Rendering\Transport\Enumerations;

enum RendererTransportState
{
  case NEW;
  case STARTING;
  case RUNNING;
  case STOPPING;
  case STOPPED;
  case FAILED;
}
