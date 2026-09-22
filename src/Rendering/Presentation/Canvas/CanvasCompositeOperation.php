<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Validated plain drawing data, without callbacks, expressions or renderer-owned time. */
final readonly class CanvasCompositeOperation
{
  public array $data;

  public function __construct(array $data)
  {
    $type = $data['type'] ?? '';
    $required = match ($type) {
      'image' => ['asset', 'destination'], 'fill' => ['destination', 'brush'], 'stroke' => ['points', 'width', 'brush'],
      default => throw new InvalidArgumentException('Unsupported composite operation.'),
    };
    CanvasCompositeValues::validateKeys($data, ['type', ...$required],
      ['opacity', 'blend', 'masks', ...($type === 'image' ? ['source', 'displacement'] : [])]);
    $blend = $data['blend'] ?? 'source_over';
    if (!in_array($blend, ['source_over', 'screen'], true)) { throw new InvalidArgumentException('Unsupported composite blend.'); }
    $result = ['type' => $type, 'opacity' => CanvasCompositeValues::getNumber($data['opacity'] ?? 1, 0, 1),
      'blend' => $blend, 'masks' => CanvasCompositeMask::getMasks($data['masks'] ?? [])];
    if ($type !== 'stroke') { $result['destination'] = CanvasCompositeValues::getRectangle($data['destination']); }
    else {
      $result['points'] = array_map(CanvasCompositeValues::getPoint(...), CanvasCompositeValues::getList($data['points'], 2, 256));
      $result['width'] = CanvasCompositeValues::getNumber($data['width'], 0, 256);
      if ($result['width'] <= 0) { throw new InvalidArgumentException('Composite strokes require positive width.'); }
    }
    if ($type !== 'image') {
      if (!is_array($data['brush'])) { throw new InvalidArgumentException('Composite brush must be a descriptor.'); }
      $result['brush'] = new CanvasCompositeBrush($data['brush'])->data;
    } else {
      $result['asset'] = CanvasCompositeValues::getAsset($data['asset']);
      if (isset($data['source'])) { $result['source'] = CanvasCompositeValues::getRectangle($data['source'], true); }
      if (isset($data['displacement'])) {
        $grid = $data['displacement'];
        CanvasCompositeValues::validateKeys($grid, ['columns', 'rows', 'offsets'], ['masks']);
        foreach (['columns', 'rows'] as $axis) {
          if (!is_int($grid[$axis]) || $grid[$axis] < 2 || $grid[$axis] > 64) {
            throw new InvalidArgumentException('Composite displacement axes must be in 2..64.');
          }
        }
        $count = $grid['columns'] * $grid['rows'];
        $result['displacement'] = ['columns' => $grid['columns'], 'rows' => $grid['rows'],
          'offsets' => array_map(static fn($pair) => CanvasCompositeValues::getPoint($pair, -4096, 4096),
            CanvasCompositeValues::getList($grid['offsets'], $count, $count)),
          'masks' => CanvasCompositeMask::getMasks($grid['masks'] ?? [])];
      }
    }
    $this->data = $result;
  }

  public function assertWithin(int $width, int $height): void
  {
    if (isset($this->data['destination'])) {
      new CanvasRectangle(...array_values($this->data['destination']))->assertWithin($width, $height);
    }
  }

  /** @return list<string> */
  public function getAssets(): array
  {
    $assets = isset($this->data['asset']) ? [$this->data['asset']] : [];
    foreach ([...$this->data['masks'], ...($this->data['displacement']['masks'] ?? [])] as $mask) {
      if (isset($mask['asset'])) { $assets[] = $mask['asset']; }
    }
    return array_values(array_unique($assets));
  }
}
