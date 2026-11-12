<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

enum CinematicCameraOperation: string
{
  case DETACH = 'detach';
  case ATTACH = 'attach';
  case RESET = 'reset';
  case FOCUS = 'focus';
  case PAN = 'pan';
  case ROUTE = 'route';
  case TRACK = 'track';
  case SHAKE = 'shake';
  case RESTORE = 'restore';
}
