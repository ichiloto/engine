<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

enum MenuRowKind
{
  case RECORD;
  case HEADING;
  case COMMAND;
  case BUTTON;

  public function getLabelAlignment(): HorizontalAlignment
  {
    return match ($this) {
      self::RECORD, self::HEADING, self::COMMAND => HorizontalAlignment::LEFT,
      self::BUTTON => HorizontalAlignment::CENTER,
    };
  }

  public function isAction(): bool
  {
    return $this === self::COMMAND || $this === self::BUTTON;
  }
}
