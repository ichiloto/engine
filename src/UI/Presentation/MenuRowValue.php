<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;

/** Already formatted by the owner; directional values share the theme's arrow artwork. */
final readonly class MenuRowValue
{
  public function __construct(public string $text, public ?PresentationColor $color = null,
    public ?MenuDirection $direction = null)
  {
    new PresentationTextRun(0, 0, $text);
  }

  public static function getArrow(MenuDirection $direction, ?PresentationColor $color = null): self
  {
    return new self($direction->getGlyph(), $color, $direction);
  }
}
