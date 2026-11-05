<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCoverageBrush;
use InvalidArgumentException;

/** Authored logical-pixel profile for a once-playing detached graphical trail. */
final readonly class BattleEffectTrail
{
  public array $data;

  public function __construct(array $data)
  {
    $ranges = [
      'length' => [0.1, 2048], 'spacing' => [0.1, 64],
      'startRadius' => [0.01, 64], 'endRadius' => [0.01, 64], 'radiusPower' => [0.1, 8],
      'startOpacity' => [0, 1], 'endOpacity' => [0, 1], 'opacityPower' => [0.1, 8],
      'fadeLengthRatio' => [0, 1],
    ];
    CanvasCompositeValues::validateKeys($data, [...array_keys($ranges), 'brush']);
    $values = [];
    foreach ($ranges as $field => [$minimum, $maximum]) {
      $values[$field] = CanvasCompositeValues::getNumber($data[$field], $minimum, $maximum);
    }
    if (ceil($values['length'] / $values['spacing']) + 1 > 128) {
      throw new InvalidArgumentException('Battle trail exceeds the 128-dab coverage limit.');
    }
    if (!is_array($data['brush'])) {
      throw new InvalidArgumentException('Battle trail requires a coverage brush.');
    }
    $this->data = [...$values, 'brush' => new CanvasCoverageBrush($data['brush'])->data];
  }
}
