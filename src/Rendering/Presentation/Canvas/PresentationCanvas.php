<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** One complete canvas snapshot. It contains drawing state, never gameplay commands. */
final readonly class PresentationCanvas
{
  /** @var list<CanvasImage> */
  public array $images;
  /** @var list<CanvasIndicator> */
  public array $indicators;
  /** @var list<CanvasTextLayer> */
  public array $textLayers;

  /** @param list<CanvasImage> $images
   * @param list<CanvasIndicator> $indicators
   * @param list<CanvasTextLayer> $textLayers
   */
  public function __construct(
    public int $width,
    public int $height,
    array $images = [],
    array $indicators = [],
    array $textLayers = [],
  )
  {
    if ($width < 1 || $height < 1 || $width > CanvasValidation::MAX_EXTENT || $height > CanvasValidation::MAX_EXTENT) {
      throw new InvalidArgumentException('Canvas dimensions must be in 1..16384.');
    }
    $this->images = CanvasValidation::orderedList($images, CanvasImage::class, 1024);
    $this->indicators = CanvasValidation::orderedList($indicators, CanvasIndicator::class, 2048);
    $this->textLayers = CanvasValidation::orderedList($textLayers, CanvasTextLayer::class, 64);
    $imageIds = [];
    foreach ($this->images as $image) {
      $image->destination->assertWithin($width, $height);
      $image->clipRect?->assertWithin($width, $height);
      $imageIds[$image->id] = true;
    }
    foreach ($this->indicators as $indicator) {
      $indicator->bounds->assertWithin($width, $height);
      if (!isset($imageIds[$indicator->imageId])) {
        throw new InvalidArgumentException('Canvas indicators must reference an image in the same frame.');
      }
    }
    $runs = $scalars = 0;
    foreach ($this->textLayers as $text) {
      $text->bounds->assertWithin($width, $height);
      $text->paintBounds->assertWithin($width, $height);
      $text->clipRect?->assertWithin($width, $height);
      $runs += count($text->runs);
      foreach ($text->runs as $run) { $scalars += mb_strlen($run->text, 'UTF-8'); }
    }
    if ($runs > 32768 || $scalars > 524288) {
      throw new InvalidArgumentException('Canvas text exceeds renderer run/scalar limits.');
    }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['width' => $this->width, 'height' => $this->height,
      'images' => array_map(static fn(CanvasImage $image) => $image->toArray(), $this->images),
      'indicators' => array_map(static fn(CanvasIndicator $indicator) => $indicator->toArray(), $this->indicators),
      'textLayers' => array_map(static fn(CanvasTextLayer $text) => $text->toArray(), $this->textLayers)];
  }
}
