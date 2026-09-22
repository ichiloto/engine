<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

final readonly class CanvasCompositeMask
{
  public array $data;

  public function __construct(array $data)
  {
    $type = $data['type'] ?? '';
    $required = match ($type) {
      'polygon' => ['contours'], 'ellipse' => ['center', 'radius'], 'image_alpha' => ['asset', 'destination'],
      default => throw new InvalidArgumentException('Unsupported composite mask.'),
    };
    CanvasCompositeValues::validateKeys($data, ['type', ...$required],
      ['invert', $type === 'image_alpha' ? 'source' : 'feather']);
    $result = ['type' => $type, 'invert' => CanvasCompositeValues::getBoolean($data['invert'] ?? false)];
    if ($type === 'image_alpha') {
      $result['asset'] = CanvasCompositeValues::getAsset($data['asset']);
      $result['destination'] = CanvasCompositeValues::getRectangle($data['destination']);
      if (isset($data['source'])) { $result['source'] = CanvasCompositeValues::getRectangle($data['source'], true); }
    } else {
      $result['feather'] = CanvasCompositeValues::getNumber($data['feather'] ?? 0, 0, 4096);
      if ($type === 'polygon') {
        $result['contours'] = [];
        foreach (CanvasCompositeValues::getList($data['contours'], 1, 8) as $contour) {
          $points = array_map(CanvasCompositeValues::getPoint(...), CanvasCompositeValues::getList($contour, 3, 128));
          foreach ($points as $index => $point) {
            if ($point === $points[($index + 1) % count($points)]) {
              throw new InvalidArgumentException('Composite polygon edges require distinct endpoints.');
            }
          }
          $result['contours'][] = $points;
        }
      } else {
        $result['center'] = CanvasCompositeValues::getPoint($data['center']);
        $result['radius'] = CanvasCompositeValues::getPoint($data['radius'], 0);
        if (min($result['radius']) <= 0) { throw new InvalidArgumentException('Composite ellipse radii must be positive.'); }
      }
    }
    $this->data = $result;
  }

  public static function getMasks(mixed $values): array
  {
    return array_map(static function ($data): array {
      if (!is_array($data)) { throw new InvalidArgumentException('Composite mask must be a descriptor.'); }
      return new self($data)->data;
    }, CanvasCompositeValues::getList($values, 0, 8));
  }
}
