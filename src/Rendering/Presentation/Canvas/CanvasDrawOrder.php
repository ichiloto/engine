<?php
declare(strict_types=1);
namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

final class CanvasDrawOrder
{
  /** @return list<array{kind:CanvasDrawingKind, drawing:CanvasImage|CanvasComposite|CanvasIndicator|CanvasTextLayer|CanvasLabel|CanvasGroup, index:int}> */
  public static function getItems(PresentationCanvas $canvas): array
  {
    $items = [];
    foreach ([[CanvasDrawingKind::IMAGE, $canvas->images], [CanvasDrawingKind::COMPOSITE, $canvas->composites],
      [CanvasDrawingKind::INDICATOR, $canvas->indicators], [CanvasDrawingKind::TEXT, $canvas->textLayers],
      [CanvasDrawingKind::LABEL, $canvas->labels], [CanvasDrawingKind::GROUP, $canvas->groups]] as [$kind, $drawings]) {
      foreach ($drawings as $index => $drawing) { $items[] = ['kind' => $kind, 'drawing' => $drawing, 'index' => $index]; }
    }
    usort($items, static fn(array $a, array $b): int =>
      [$a['drawing']->layer, $a['kind']->value, $a['index']] <=> [$b['drawing']->layer, $b['kind']->value, $b['index']]);
    return $items;
  }
}
