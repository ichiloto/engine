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
    $images = $indicators = $text = $labels = $runs = $scalars = 0;
    $compositeCount = 0;
    foreach ($canvases as $canvas) {
      if (!$canvas instanceof PresentationCanvas) { throw new InvalidArgumentException('Canvas frame roots require typed canvases.'); }
      $compositeCount += count($canvas->composites);
    }
    CanvasCompositeBudget::assertSurfaceCount($compositeCount, 'Aggregate canvases');
    $composites = [];
    foreach ($canvases as $canvas) {
      $images += count($canvas->images);
      $indicators += count($canvas->indicators);
      $text += count($canvas->textLayers);
      $labels += count($canvas->labels);
      if ($images > 1024 || $indicators > 2048 || $text > StyledPresentationFrame::MAX_TEXT_LAYERS
        || $labels > CanvasValidation::MAX_LABELS) {
        throw new InvalidArgumentException('Aggregate canvases exceed image, indicator, grid-text or label-instance limits.');
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
      array_push($composites, ...$canvas->composites);
    }
    CanvasCompositeBudget::assertWithin($composites, 'Aggregate canvases');
  }
}
