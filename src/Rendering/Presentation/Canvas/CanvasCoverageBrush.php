<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** A radial paint profile shared by authored effects and retained drawing. */
final readonly class CanvasCoverageBrush
{
  public const array FIELDS = ['falloff', 'alphaSteps', 'minimumAlpha', 'bands'];
  public array $data;

  public function __construct(array $data)
  {
    CanvasCompositeValues::validateKeys($data, self::FIELDS);
    $steps = $data['alphaSteps'];
    $minimum = $data['minimumAlpha'];
    if (!is_int($steps) || $steps < 1 || $steps > 255 || !is_int($minimum) || $minimum < 1 || $minimum > $steps) {
      throw new InvalidArgumentException('Coverage dab alpha steps must be integers in 1..255 with a minimum within the step range.');
    }
    $bands = [];
    $previous = 0.0;
    foreach (CanvasCompositeValues::getList($data['bands'], 1, 8) as $band) {
      CanvasCompositeValues::validateKeys($band, ['until', 'color']);
      $until = CanvasCompositeValues::getNumber($band['until'], 0, 1);
      if ($until <= $previous) {
        throw new InvalidArgumentException('Coverage dab band bounds must strictly increase.');
      }
      $bands[] = ['until' => $until, 'color' => CanvasCompositeValues::getColor($band['color'])];
      $previous = $until;
    }
    if ($previous !== 1.0) {
      throw new InvalidArgumentException('Coverage dab bands must cover the entire radius.');
    }
    $this->data = [
      'falloff' => CanvasCompositeValues::getNumber($data['falloff'], 0.1, 8),
      'alphaSteps' => $steps,
      'minimumAlpha' => $minimum,
      'bands' => $bands,
    ];
  }
}
