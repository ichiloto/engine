<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

/** Incoming logical surface context, available while the outgoing scene remains visible. */
final readonly class AssetHoldCanvas
{
  public function __construct(public int $width, public int $height, public CanvasViewportFit $viewportFit)
  {
    foreach ([$width, $height] as $extent) {
      AssetHoldValidation::readInteger($extent, 1, CanvasValidation::MAX_EXTENT, AssetHoldRejection::INVALID_CANVAS);
    }
  }

  public static function fromArray(array $value): self
  {
    $reason = AssetHoldRejection::INVALID_CANVAS;
    AssetHoldValidation::assertKeys($value, ['width', 'height', 'viewportFit'], [], $reason);
    $fit = is_string($value['viewportFit']) ? CanvasViewportFit::tryFrom($value['viewportFit']) : null;
    if ($fit === null) { AssetHoldValidation::reject($reason, 'Expected native or contain viewport fitting.'); }
    return new self(AssetHoldValidation::readInteger($value['width'], 1, CanvasValidation::MAX_EXTENT, $reason),
      AssetHoldValidation::readInteger($value['height'], 1, CanvasValidation::MAX_EXTENT, $reason), $fit);
  }

  public static function fromCanvas(PresentationCanvas $canvas): self
  {
    return new self($canvas->width, $canvas->height, $canvas->viewportFit);
  }

  /** @return array{width: int, height: int, viewportFit: string} */
  public function toArray(): array
  {
    return ['width' => $this->width, 'height' => $this->height, 'viewportFit' => $this->viewportFit->value];
  }
}
