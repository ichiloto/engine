<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use InvalidArgumentException;

/** One complete canvas snapshot. It contains drawing state, never gameplay commands. */
final readonly class PresentationCanvas
{
  /** Default authored logical surface; native window sizing remains independent. */
  public const int DEFAULT_WIDTH = 1350;
  public const int DEFAULT_HEIGHT = 720;

  /** @var list<CanvasImage> */
  public array $images;
  /** @var list<CanvasIndicator> */
  public array $indicators;
  /** @var list<CanvasTextLayer> */
  public array $textLayers;
  /** @var list<CanvasComposite> */
  public array $composites;
  /** @var list<CanvasLabel> */
  public array $labels;
  /** PHP-only overlay avoidance areas. Null retains conservative protection for unannotated canvases.
   * @var list<CanvasRectangle>|null
   */
  public ?array $protectedAreas;

  /** Sources required by cached overview operations, independent of visible field viewports. */
  public function getReferencedWorldIds(): array
  {
    $ids = [];
    foreach ($this->composites as $composite) {
      foreach ($composite->operations as $operation) {
        if ($operation->data['type'] === 'world') { $ids[$operation->data['worldId']] = true; }
      }
    }
    return array_keys($ids);
  }

  public function assertWorldReferences(?\Ichiloto\Engine\Rendering\Presentation\PresentationWorld $world): void
  {
    foreach ($this->getReferencedWorldIds() as $id) {
      if ($world?->id !== $id) { throw new InvalidArgumentException('A canvas overview requires its retained source world.'); }
    }
  }

  /** Resolve each source before stacking, so one unannotated overlay cannot erase another owner's safe areas.
   * @return list<CanvasRectangle>
   */
  public function getOverlayProtection(): array
  {
    if ($this->protectedAreas !== null) { return $this->protectedAreas; }
    $labelAreas = [];
    foreach ($this->labels as $label) {
      $paint = $label->paintBounds;
      if ($label->clipRect !== null) { $labelAreas[] = $label->clipRect; }
      elseif ($paint->width > 0 && $paint->height > 0) {
        $labelAreas[] = new CanvasRectangle($paint->x, $paint->y, $paint->width, $paint->height);
      }
    }
    return [...array_map(static fn(CanvasImage $image) => $image->clipRect ?? $image->destination, $this->images),
      ...array_map(static fn(CanvasTextLayer $text) => $text->clipRect ?? $text->paintBounds, $this->textLayers),
      ...$labelAreas,
      ...array_map(static fn(CanvasComposite $composite) => $composite->clipRect ?? $composite->destination, $this->composites),
      ...array_map(static fn(CanvasIndicator $indicator) => $indicator->bounds, $this->indicators)];
  }

  /** Stack a live screen-space layer without replacing the retained scene underneath. */
  public static function composeOverlay(?self $base, self $overlay, string $idPrefix = ''): self
  {
    if ($base === null && $idPrefix === '') { return $overlay; }
    $base ??= new self($overlay->width, $overlay->height, viewportFit: $overlay->viewportFit);
    if ($base->width !== $overlay->width || $base->height !== $overlay->height) {
      throw new InvalidArgumentException('Canvas overlays must share their logical surface.');
    }
    $offset = 1 + max([0, ...array_column($base->images, 'layer'), ...array_column($base->textLayers, 'layer'),
      ...array_column($base->indicators, 'layer'), ...array_column($base->composites, 'layer'),
      ...array_column($base->labels, 'layer')]);
    return new self($base->width, $base->height,
      [...$base->images, ...array_map(static fn($image) => new CanvasImage($idPrefix . $image->id, $image->asset,
        $image->destination, $offset + $image->layer, $image->sourceRect, $image->opacity,
        $image->clipRect, $image->brightness, $image->flipX, $image->flipY), $overlay->images)],
      [...$base->indicators, ...array_map(static fn($indicator) => new CanvasIndicator($idPrefix . $indicator->id,
        $idPrefix . $indicator->imageId, $indicator->kind, $indicator->bounds, $indicator->strokeWidth, $indicator->color,
        $offset + $indicator->layer), $overlay->indicators)],
      CanvasTextBatch::compact([...$base->textLayers, ...array_map(static fn($text) => new CanvasTextLayer($idPrefix . $text->id, $offset + $text->layer,
        $text->x, $text->y, $text->grid, $text->runs, $text->clipRect, $text->opacity, $text->glyphEffects), $overlay->textLayers)]),
      [...$base->composites, ...array_map(static fn($composite) => new CanvasComposite($idPrefix . $composite->id,
        $composite->width, $composite->height, $composite->destination, $composite->operations,
        $offset + $composite->layer, $composite->opacity, $composite->clipRect), $overlay->composites)],
      [...$base->getOverlayProtection(), ...$overlay->getOverlayProtection()],
      [...$base->presentationOwners, ...$overlay->presentationOwners],
      [...$base->labels, ...array_map(static fn(CanvasLabel $label) => new CanvasLabel($idPrefix . $label->id,
        $label->measurement, $label->x, $label->y, $offset + $label->layer, $label->clipRect, $label->opacity,
        $label->rotation), $overlay->labels)], $base->viewportFit);
  }

  /** @param list<CanvasImage> $images
   * @param list<CanvasIndicator> $indicators
   * @param list<CanvasTextLayer> $textLayers
   * @param list<CanvasComposite> $composites
   * @param list<CanvasLabel> $labels
   */
  public function __construct(
    public int $width,
    public int $height,
    array $images = [],
    array $indicators = [],
    array $textLayers = [],
    array $composites = [],
    ?array $protectedAreas = null,
    public array $presentationOwners = [],
    array $labels = [],
    public CanvasViewportFit $viewportFit = CanvasViewportFit::NATIVE,
  )
  {
    if ($width < 1 || $height < 1 || $width > CanvasValidation::MAX_EXTENT || $height > CanvasValidation::MAX_EXTENT) {
      throw new InvalidArgumentException('Canvas dimensions must be in 1..16384.');
    }
    if (!array_is_list($presentationOwners) || count($presentationOwners) > 1024) {
      throw new InvalidArgumentException('Canvas presentation owners require a bounded list.');
    }
    foreach ($presentationOwners as $owner) {
      if (!is_string($owner)) { throw new InvalidArgumentException('Canvas presentation owners require layer IDs.'); }
      CanvasValidation::id($owner);
    }
    $this->images = CanvasValidation::orderedList($images, CanvasImage::class, 1024);
    $this->indicators = CanvasValidation::orderedList($indicators, CanvasIndicator::class, 2048);
    $this->textLayers = CanvasValidation::orderedList($textLayers, CanvasTextLayer::class, StyledPresentationFrame::MAX_TEXT_LAYERS);
    $this->labels = CanvasValidation::orderedList($labels, CanvasLabel::class, CanvasValidation::MAX_LABELS);
    foreach ($this->labels as $label) { $label->assertWithin($width, $height); }
    $this->composites = CanvasValidation::orderedList($composites, CanvasComposite::class, 8);
    $areas = null;
    if ($protectedAreas !== null) {
      if (!array_is_list($protectedAreas) || count($protectedAreas) > 32768) {
        throw new InvalidArgumentException('Canvas protection requires a bounded list of rectangles.');
      }
      $areas = [];
      foreach ($protectedAreas as $area) {
        if (!$area instanceof CanvasRectangle) {
          throw new InvalidArgumentException('Canvas protection requires typed rectangles.');
        }
        $area->assertWithin($width, $height);
        $areas[] = $area;
      }
    }
    $this->protectedAreas = $areas;
    $pixels = $operations = $nodes = 0;
    foreach ($this->composites as $composite) {
      $composite->destination->assertWithin($width, $height);
      $composite->clipRect?->assertWithin($width, $height);
      $pixels += $composite->width * $composite->height;
      $operations += count($composite->operations);
      foreach ($composite->operations as $operation) { $nodes += count($operation->data['displacement']['offsets'] ?? []); }
    }
    if ($pixels > 8388608 || $operations > 256 || $nodes > 16384) {
      throw new InvalidArgumentException('Canvas composites exceed their combined raster, operation or displacement budget.');
    }
    CanvasCompositeBudget::getWork($this->composites);
    $imageIds = [];
    foreach ($this->images as $image) {
      $image->destination->assertWithin($width, $height);
      $image->clipRect?->assertWithin($width, $height);
      $imageIds[$image->id] = true;
    }
    foreach ($this->composites as $composite) { $imageIds[$composite->id] = true; }
    foreach ($this->indicators as $indicator) {
      $indicator->bounds->assertWithin($width, $height);
      if (!isset($imageIds[$indicator->imageId])) {
        throw new InvalidArgumentException('Canvas indicators must reference an image or composite in the same frame.');
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
      ...($this->viewportFit === CanvasViewportFit::NATIVE ? [] : ['viewportFit' => $this->viewportFit->value]),
      'images' => array_map(static fn(CanvasImage $image) => $image->toArray(), $this->images),
      'indicators' => array_map(static fn(CanvasIndicator $indicator) => $indicator->toArray(), $this->indicators),
      'textLayers' => array_map(static fn(CanvasTextLayer $text) => $text->toArray(), $this->textLayers),
      ...($this->labels === [] ? [] : ['labels' => array_map(static fn(CanvasLabel $label) => $label->toArray(), $this->labels)]),
      ...($this->composites === [] ? [] : ['composites' => array_map(static fn($item) => $item->toArray(), $this->composites)])];
  }
}
