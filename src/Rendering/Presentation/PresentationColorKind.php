<?php

namespace Ichiloto\Engine\Rendering\Presentation;

enum PresentationColorKind: string
{
  case ANSI16 = 'ansi16';
  case ANSI256 = 'ansi256';
  case RGB = 'rgb';
}
