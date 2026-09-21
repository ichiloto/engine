<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;

/** Already formatted by the owner, including comparison signs and colors. */
final readonly class MenuRowValue
{
  public function __construct(public string $text, public ?PresentationColor $color = null)
  {
    new PresentationTextRun(0, 0, $text);
  }
}
