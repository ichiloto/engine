<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

enum MenuRowKind
{
  case RECORD;
  case HEADING;
  case COMMAND;
  case BUTTON;

  public function isAction(): bool
  {
    return $this === self::COMMAND || $this === self::BUTTON;
  }
}
