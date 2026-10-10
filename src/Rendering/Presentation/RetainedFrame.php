<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

/** An immutable, session-owned composition, not a screenshot or a gameplay snapshot. @internal */
final readonly class RetainedFrame
{
  public function __construct(
    public object $owner,
    public array $values,
    public array $textRows,
    public ?PresentationWorld $world,
    public ?array $viewport,
    public ?PresentationCanvas $canvas,
    public bool $canvasIsOverlay,
  ) {}
}
