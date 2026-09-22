<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues as V;

final class TitleAmbientPresentation
{
  /** @return list<CanvasCompositeOperation> */
  public static function getOperations(string $asset, array $effects, float $time, float $weight): array
  {
    V::validateKeys($effects, [], ['textureDrift', 'flowingWater', 'flame']);
    $operations = [];
    foreach ($effects as $kind => $data) {
      $part = match ($kind) {
        'textureDrift' => TitleTextureMotion::getDriftOperations($asset, $data, $time, $weight),
        'flowingWater' => TitleTextureMotion::getWaterOperations($asset, $data, $time, $weight),
        'flame' => TitleFlameMotion::getOperations($data, $time, $weight),
      };
      array_push($operations, ...$part);
    }
    return array_map(static fn($operation) => new CanvasCompositeOperation($operation), $operations);
  }
}
