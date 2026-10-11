<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use InvalidArgumentException;

/** All local roots share one candidate's native drawing pools, including reused drawings. */
final class CanvasFrameBudget
{
  /** @param list<PresentationCanvas> $canvases */
  public static function assertCanvases(array $canvases): void
  {
    if (!array_is_list($canvases) || count($canvases) > 1025) {
      throw new InvalidArgumentException('Canvas frame roots require a list of at most 1025 canvases.');
    }
    $images = $indicators = $text = $runs = $scalars = $pixels = $operations = $nodes = 0;
    $composites = [];
    foreach ($canvases as $canvas) {
      if (!$canvas instanceof PresentationCanvas) {
        throw new InvalidArgumentException('Canvas frame roots require typed canvases.');
      }
      $images += count($canvas->images);
      $indicators += count($canvas->indicators);
      $text += count($canvas->textLayers) + count($canvas->labels);
      if ($images > 1024 || $indicators > 2048 || $text > StyledPresentationFrame::MAX_TEXT_LAYERS) {
        throw new InvalidArgumentException('Aggregate canvases exceed image, indicator or shared text limits.');
      }
      foreach ($canvas->textLayers as $layer) {
        $runs += count($layer->runs);
        if ($runs > StyledPresentationFrame::MAX_TEXT_RUNS) {
          throw new InvalidArgumentException('Aggregate canvases exceed text run limits.');
        }
        foreach ($layer->runs as $run) {
          $scalars += mb_strlen($run->text, 'UTF-8');
          if ($scalars > StyledPresentationFrame::MAX_TEXT_SCALARS) {
            throw new InvalidArgumentException('Aggregate canvases exceed text scalar limits.');
          }
        }
      }
      // Measurements carry geometry, not source text; native layout admission counts label lines/scalars.
      if (count($composites) + count($canvas->composites) > 8) {
        throw new InvalidArgumentException('Aggregate canvases exceed composite limits.');
      }
      foreach ($canvas->composites as $composite) {
        $pixels += $composite->width * $composite->height;
        $operations += count($composite->operations);
        foreach ($composite->operations as $operation) {
          $nodes += count($operation->data['displacement']['offsets'] ?? []);
        }
        if ($pixels > 8388608 || $operations > 256 || $nodes > 16384) {
          throw new InvalidArgumentException('Aggregate canvases exceed composite raster, operation or displacement limits.');
        }
        $composites[] = $composite;
      }
    }
    if (CanvasCompositeBudget::getWork($composites) > CanvasCompositeBudget::MAX_WORK) {
      throw new InvalidArgumentException('Aggregate canvases exceed cold raster work limits.');
    }
  }
}
