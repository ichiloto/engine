<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

final readonly class BattleCommandPreviewFrame
{
  public function __construct(public int $frame, public string $phase,
    public ?PresentationCanvas $canvas, public array $terminalLines,
    public array $cues, public array $crossedCues, public array $diagnostics,
    public array $authoredFrames = [], public ?PresentationCanvas $terminalCanvas = null) {}

  public function toArray(): array
  {
    return ['frame' => $this->frame, 'phase' => $this->phase, 'canvas' => $this->canvas?->toArray(),
      'terminalLines' => $this->terminalLines, 'cues' => $this->cues,
      'crossedCues' => $this->crossedCues, 'diagnostics' => $this->diagnostics,
      'authoredFrames' => $this->authoredFrames, 'terminalCanvas' => $this->terminalCanvas?->toArray()];
  }
}
