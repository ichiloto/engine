<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use InvalidArgumentException;

/** One icon-list value: a theme icon role, and the name shown where that icon is not drawn (always in Terminal). */
final readonly class MenuDetailIcon
{
  public function __construct(public string $role, public string $name)
  {
    CanvasValidation::id($role);
    if (trim($name) === '' || str_contains($name, "\n")) { throw new InvalidArgumentException('A detail icon needs a one-line name.'); }
  }
}
