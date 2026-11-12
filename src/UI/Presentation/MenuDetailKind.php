<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** What a detail entry holds. Field kinds share one label column; the style aligns and places each kind. */
enum MenuDetailKind: string
{
  case PROSE = 'prose';
  case HEADING = 'heading';
  case TEXT = 'text';
  case NUMBER = 'number';
  case ICONS = 'icons';

  public function isField(): bool
  {
    return $this === self::TEXT || $this === self::NUMBER || $this === self::ICONS;
  }
}
