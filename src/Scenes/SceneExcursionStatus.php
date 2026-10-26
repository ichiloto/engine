<?php

namespace Ichiloto\Engine\Scenes;

enum SceneExcursionStatus
{
  case COMPLETED;
  case CANCELLED;
  case ABANDONED;
  case FAILED;
}
