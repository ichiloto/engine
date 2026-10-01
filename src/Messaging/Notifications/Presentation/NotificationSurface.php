<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

final readonly class NotificationSurface
{
  public function __construct(public PresentationCanvas $canvas, public CanvasRectangle $bounds) {}
}
