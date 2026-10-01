<?php

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

final readonly class DialogueSurface
{
    /** @param list<string> $excludedLayers Terminal layers replaced in this renderer snapshot only. */
    public function __construct(public ?PresentationCanvas $canvas, public bool $isOverlay,
        public array $excludedLayers = []) {}
}
