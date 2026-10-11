<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;

/** Immutable transport result; layoutId is opaque and belongs only to its live session. */
final readonly class TextMeasurement
{
  public function __construct(public string $layoutId, public TextMetrics $metrics)
  {
    CanvasValidation::id($layoutId);
  }
}
