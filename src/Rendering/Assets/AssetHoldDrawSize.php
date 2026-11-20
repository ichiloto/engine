<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;

/** Authored drawing extent in logical canvas units; the renderer supplies device scale. */
final readonly class AssetHoldDrawSize
{
  public function __construct(public float $width, public float $height)
  {
    foreach ([$width, $height] as $extent) {
      AssetHoldValidation::readNumber($extent, 0, CanvasValidation::MAX_EXTENT, AssetHoldRejection::INVALID_DRAW_SIZE);
      if ($extent <= 0) { AssetHoldValidation::reject(AssetHoldRejection::INVALID_DRAW_SIZE, 'Drawing extents must be positive.'); }
    }
  }

  public static function fromArray(array $value): self
  {
    $reason = AssetHoldRejection::INVALID_DRAW_SIZE;
    AssetHoldValidation::assertKeys($value, ['width', 'height'], [], $reason);
    return new self(AssetHoldValidation::readNumber($value['width'], 0, CanvasValidation::MAX_EXTENT, $reason),
      AssetHoldValidation::readNumber($value['height'], 0, CanvasValidation::MAX_EXTENT, $reason));
  }

  /** @return array{width: float, height: float} */
  public function toArray(): array { return ['width' => $this->width, 'height' => $this->height]; }
}
