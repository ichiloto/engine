<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

final readonly class CanvasCompositeBrush
{
  public array $data;

  public function __construct(array $data)
  {
    $type = $data['type'] ?? '';
    $required = match ($type) {
      'solid' => ['color'], 'linear' => ['start', 'end', 'stops'], 'radial' => ['center', 'radius', 'stops'],
      default => throw new InvalidArgumentException('Unsupported composite brush.'),
    };
    CanvasCompositeValues::validateKeys($data, ['type', ...$required]);
    $result = ['type' => $type];
    if ($type === 'solid') { $result['color'] = CanvasCompositeValues::getColor($data['color']); }
    else {
      foreach (array_diff($required, ['stops']) as $key) {
        $result[$key] = CanvasCompositeValues::getPoint($data[$key]);
      }
      if (($type === 'linear' && $result['start'] === $result['end'])
        || ($type === 'radial' && min($result['radius']) <= 0)) {
        throw new InvalidArgumentException('Composite gradient must have positive extent.');
      }
      $result['stops'] = [];
      $previous = -1;
      foreach (CanvasCompositeValues::getList($data['stops'], 2, 8) as $stop) {
        CanvasCompositeValues::validateKeys($stop, ['offset', 'color'], ['opacity']);
        $offset = CanvasCompositeValues::getNumber($stop['offset'], 0, 1);
        if ($offset <= $previous) { throw new InvalidArgumentException('Gradient stops must strictly increase.'); }
        $previous = $offset;
        $result['stops'][] = ['offset' => $offset, 'color' => CanvasCompositeValues::getColor($stop['color']),
          'opacity' => CanvasCompositeValues::getNumber($stop['opacity'] ?? 1, 0, 1)];
      }
      if ($result['stops'][0]['offset'] !== 0.0 || $previous !== 1.0) {
        throw new InvalidArgumentException('Composite gradients need endpoint stops at zero and one.');
      }
    }
    $this->data = $result;
  }
}
