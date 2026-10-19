<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Validated plain drawing data, without callbacks, expressions or renderer-owned time. */
final readonly class CanvasCompositeOperation
{
  public array $data;
  /** @var list<CanvasWorldSubject> Validated map-owned image groups, independent of wire encoding. */
  public array $worldSubjects;

  public function __construct(array $data)
  {
    $type = $data['type'] ?? '';
    $worldSubjects = [];
    $required = match ($type) {
      'image' => ['asset', 'destination'], 'fill' => ['destination', 'brush'], 'stroke' => ['points', 'width', 'brush'],
      'world' => ['worldId', 'destination', 'subjects'],
      default => throw new InvalidArgumentException('Unsupported composite operation.'),
    };
    CanvasCompositeValues::validateKeys($data, ['type', ...$required],
      ['opacity', 'blend', 'masks', ...($type === 'image' ? ['source', 'displacement'] : ($type === 'world' ? ['source'] : []))]);
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
    if ($type === 'world') {
      if (!is_string($data['worldId'])) { throw new InvalidArgumentException('A world overview requires a retained world id.'); }
      CanvasValidation::id($data['worldId']);
      if (max($result['destination']['width'], $result['destination']['height']) > 1024) {
        throw new InvalidArgumentException('World overview rasters are bounded to 1024 by 1024 pixels.');
      }
      $subjects = CanvasCompositeValues::getList($data['subjects'], 0, 128);
      $ids = $parsed = [];
      $images = 0;
      foreach ($subjects as $subject) {
        $subject = $subject instanceof CanvasWorldSubject ? $subject
          : (is_array($subject) ? CanvasWorldSubject::fromArray($subject)
            : throw new InvalidArgumentException('World overview subjects require typed image groups.'));
        if (isset($ids[$subject->id])) { throw new InvalidArgumentException('World overview subject ids must be unique.'); }
        $ids[$subject->id] = true;
        $images += count($subject->images);
        $parsed[] = $subject->toArray();
        $worldSubjects[] = $subject;
      }
      if ($images > 1024) { throw new InvalidArgumentException('World overview subjects exceed 1024 image pieces.'); }
      $result['worldId'] = $data['worldId'];
      $result['subjects'] = $parsed;
      if (array_key_exists('source',$data)) { $result['source']=CanvasCompositeValues::getRectangle($data['source']); }
    } elseif ($type !== 'image') {
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
    $this->worldSubjects = $worldSubjects;
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
    foreach ($this->data['subjects'] ?? [] as $subject) {
      foreach ($subject['images'] as $image) { $assets[] = $image['asset']; }
    }
    foreach ([...$this->data['masks'], ...($this->data['displacement']['masks'] ?? [])] as $mask) {
      if (isset($mask['asset'])) { $assets[] = $mask['asset']; }
    }
    return array_values(array_unique($assets));
  }
}
