<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;

/** One presentation session; borrows the shared client and its bounded I/O service. */
final class RendererPresentation
{
  private readonly RetainedPresentation $retained;

  public function __construct(
    private readonly RendererClient $client,
    private readonly RendererGridConfig $grid,
    ?Closure $serviceTransport = null,
  )
  {
    $this->retained = new RetainedPresentation($client, $serviceTransport);
  }

  public function invalidate(bool $newSession = false, ?int $expectedGeneration = null): void
  {
    $this->retained->invalidate($newSession, $expectedGeneration);
  }

  public function hasPendingUpload(): bool { return $this->retained->hasPendingUpload(); }

  /** The world of the newest frame the renderer has shown. */
  public ?PresentationWorld $presentedWorld { get => $this->retained->presentedWorld; }

  public function acknowledge(int $generation, bool $presented): void { $this->retained->acknowledge($generation, $presented); }

  public function captureFrame(): RetainedFrame { return $this->retained->captureFrame(); }

  public function prepareFrame(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot, array $sprites = [],
    ?PresentationViewport $viewport = null, ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null,
    array $worldCanvases = []): RetainedFrame
  {
    return $this->submitPresentation($snapshot, $sprites, [], $viewport, $world, $canvasOverlay, true, $worldCanvases);
  }

  public function prepareCanvas(PresentationCanvas $canvas, ?PresentationWorld $world = null): RetainedFrame
  {
    $this->validateCanvas($canvas);
    $this->validateCanvasFrame([$canvas]);
    return $this->retained->prepareCanvas($canvas, $world);
  }

  public function presentFrame(RetainedFrame $frame, ?PresentationCanvas $screenOverlay = null): bool
  {
    $this->validateWorldCanvases($frame->worldCanvases);
    if ($frame->canvas !== null) { $this->validateCanvas($frame->canvas, $frame->canvasIsOverlay); }
    if ($screenOverlay !== null) {
      $this->validateCanvas($screenOverlay, $frame->canvasIsOverlay);
      if ($frame->canvasIsOverlay && (!$this->client->supports(RendererSessionConfig::CANVAS_OVERLAY)
        || $screenOverlay->width !== $this->grid->columns * $this->grid->cellWidth
        || $screenOverlay->height !== $this->grid->rows * $this->grid->cellHeight)) {
        throw new RendererProtocolException('Retained screen overlays require canvas_overlay and the session logical surface.');
      }
    }
    $canvas = $screenOverlay === null ? $frame->canvas
      : PresentationCanvas::composeOverlay($frame->canvas, $screenOverlay);
    $canvas?->assertWorldReferences($frame->world);
    $this->validateCanvasFrame([...($canvas === null ? [] : [$canvas]),
      ...array_map(static fn($group) => $group->canvas, $frame->worldCanvases)]);
    return $this->retained->presentFrame($frame, $screenOverlay);
  }

  /**
   * @param list<PresentationSprite> $sprites
   * @param list<PresentationTileBatch> $tileBatches
   * Returns true when retained-update bytes were queued, including a staged upload.
   */
  public function present(ConsoleFrameSnapshot|ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites = [], array $tileBatches = [], ?PresentationViewport $viewport = null,
    ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null, array $worldCanvases = []): bool
  {
    return $this->submitPresentation($snapshot, $sprites, $tileBatches, $viewport, $world, $canvasOverlay, false, $worldCanvases);
  }

  private function submitPresentation(ConsoleFrameSnapshot|ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites, array $tileBatches, ?PresentationViewport $viewport,
    ?PresentationWorld $world, ?PresentationCanvas $canvasOverlay, bool $prepare, array $worldCanvases): bool|RetainedFrame
  {
    $canvasOverlay?->assertWorldReferences($world);
    foreach ($worldCanvases as $group) { $group->canvas->assertWorldReferences($world); }
    if ($snapshot->width !== $this->grid->columns || $snapshot->height !== $this->grid->rows) {
      throw new InvalidArgumentException('Console snapshot dimensions must match the fixed renderer session grid.');
    }
    foreach ($sprites as $sprite) {
      if ($sprite->sourceRect !== null && !$this->client->supports(RendererSessionConfig::SPRITE_SOURCE_RECT)) {
        throw new RendererProtocolException('Sprite sheets require negotiated sprite_source_rect support. Request it at startup and install an updated renderer.');
      }
    }
    if ($tileBatches !== []) {
      throw new RendererProtocolException('Stateless tile batches have been removed. Supply retained world layers instead.');
    }
    $slides = $viewport?->follow !== null || array_any($sprites, static fn($sprite): bool => $sprite->motion !== null);
    if ($slides && !$this->client->supports(RendererSessionConfig::FIELD_MOTION)) {
      throw new RendererProtocolException('Sprite slides and camera follow require negotiated field_motion support.');
    }
    if ($viewport !== null) {
      $viewport->assertWithin($this->grid);
    }
    if ($snapshot instanceof ConsoleFrameSnapshot) {
      $runs = [];
      foreach ($snapshot->rows as $row => $text) { $runs[] = new PresentationTextRun($row, 0, $text); }
      $snapshot = new ConsolePresentationSnapshot($snapshot->width, $snapshot->height,
        [new PresentationTextLayer('world', PresentationLayerPolicy::WORLD, $runs)]);
    }
    if ($canvasOverlay !== null) {
      if (!$this->client->supports(RendererSessionConfig::CANVAS_OVERLAY)) {
        throw new RendererProtocolException('Field canvas overlays require negotiated canvas_overlay support.');
      }
      if ($canvasOverlay->width !== $this->grid->columns * $this->grid->cellWidth
        || $canvasOverlay->height !== $this->grid->rows * $this->grid->cellHeight) {
        throw new RendererProtocolException('Canvas overlay must match the renderer session logical pixel surface.');
      }
      $this->validateCanvas($canvasOverlay, true);
    }
    $this->validateWorldCanvases($worldCanvases);
    $this->validateCanvasFrame([...($canvasOverlay === null ? [] : [$canvasOverlay]),
      ...array_map(static fn($group) => $group->canvas, $worldCanvases)]);
    return $prepare ? $this->retained->prepareFrame($snapshot, $sprites, $viewport, $world, $canvasOverlay, $worldCanvases)
      : $this->retained->present($snapshot, $sprites, $viewport, $world, $canvasOverlay, $worldCanvases);
  }

  /** Graphical frames do not require a Console snapshot or use its cell dimensions. */
  public function presentCanvas(PresentationCanvas $canvas, ?PresentationWorld $world = null): bool
  {
    $this->validateCanvas($canvas);
    $this->validateCanvasFrame([$canvas]);
    return $this->retained->presentCanvas($canvas, $world);
  }

  /** Admission is for the complete candidate, not one root or one prepared layout at a time.
   * @param list<PresentationCanvas> $canvases
   */
  private function validateCanvasFrame(array $canvases): void
  {
    CanvasFrameBudget::assertCanvases($canvases);
    if (!$this->client->supports(RendererSessionConfig::CANVAS_LABEL_INSTANCES)
      && array_sum(array_map(static fn($canvas) => count($canvas->textLayers) + count($canvas->labels), $canvases))
        > StyledPresentationFrame::MAX_TEXT_LAYERS) {
      throw new RendererProtocolException('More than 64 combined canvas grid layers and labels require negotiated canvas_label_instances support.');
    }
  }

  private function validateCanvas(PresentationCanvas $canvas, bool $isOverlay = false): void
  {
    if (!$this->client->supports(RendererSessionConfig::GRAPHICAL_CANVAS)) {
      throw new RendererProtocolException('Canvas presentation requires negotiated graphical_canvas support.');
    }
    if ($canvas->viewportFit !== CanvasViewportFit::NATIVE) {
      if ($isOverlay) {
        throw new RendererProtocolException('Field canvas overlays inherit their viewport and cannot request window fitting.');
      }
      if (!$this->client->supports(RendererSessionConfig::CANVAS_VIEWPORT_FIT)) {
        throw new RendererProtocolException('Canvas window fitting requires negotiated canvas_viewport_fit support.');
      }
    }
    if ($canvas->composites !== [] && !$this->client->supports(RendererSessionConfig::CANVAS_COMPOSITING)) {
      throw new RendererProtocolException('Canvas raster operations require negotiated canvas_compositing support.');
    }
    if (!$this->client->supports(RendererSessionConfig::CANVAS_IMAGE_ALPHA_FLIP)
      && array_any($canvas->composites, static fn($composite): bool => array_any($composite->operations,
        static fn($operation): bool => array_any($operation->data['masks'], static fn(array $mask): bool =>
          ($mask['flipX'] ?? false) || ($mask['flipY'] ?? false))))) {
      throw new RendererProtocolException('Mirrored image-alpha masks require negotiated canvas_image_alpha_flip support.');
    }
    $compositeIds = array_fill_keys(array_column($canvas->composites, 'id'), true);
    $imageIds = array_fill_keys(array_column($canvas->images, 'id'), true);
    if (array_any($canvas->indicators, static fn($indicator) => !isset($imageIds[$indicator->imageId]) && isset($compositeIds[$indicator->imageId]))
      && !$this->client->supports(RendererSessionConfig::CANVAS_COMPOSITE_INDICATORS)) {
      throw new RendererProtocolException('Composite indicators require negotiated canvas_composite_indicators support.');
    }
    if (array_any($canvas->composites, static fn($composite) => array_any($composite->operations,
        static fn($operation) => $operation->data['type'] === 'world'))
      && !$this->client->supports(RendererSessionConfig::MAP_OVERVIEW)) {
      throw new RendererProtocolException('World overview rasters require negotiated map_overview support.');
    }
    if (array_any($canvas->composites, static fn($composite) => array_any($composite->operations,
        static fn($operation) => $operation->data['type'] === 'world' && isset($operation->data['source'])))
      && !$this->client->supports(RendererSessionConfig::MAP_OVERVIEW_VIEWPORT)) {
      throw new RendererProtocolException('World map source windows require negotiated map_overview_viewport support.');
    }
    if (array_any($canvas->composites, static fn($composite) => array_any($composite->operations,
        static fn($operation) => isset($operation->data['dash'])))
      && !$this->client->supports(RendererSessionConfig::CANVAS_STROKE_DASHES)) {
      throw new RendererProtocolException('Dashed composite strokes require negotiated canvas_stroke_dashes support.');
    }
    if ($canvas->labels !== [] && (!$this->client->supports(RendererSessionConfig::CANVAS_LABELS)
      || !$this->client->supports(RendererSessionConfig::TEXT_LAYOUT))) {
      throw new RendererProtocolException('Canvas labels require negotiated canvas_labels and text_layout support.');
    }
    if (array_any($canvas->labels, static fn($label) => $label->rotation !== 0)
      && !$this->client->supports(RendererSessionConfig::CANVAS_TEXT_180)) {
      throw new RendererProtocolException('Half-turned canvas labels require negotiated canvas_text_180 support.');
    }
    foreach ($canvas->images as $image) {
      if (($image->flipX || $image->flipY) && !$this->client->supports(RendererSessionConfig::CANVAS_IMAGE_FLIP)) {
        throw new RendererProtocolException('Canvas image mirroring requires negotiated canvas_image_flip support.');
      }
      if ($image->brightness !== 1.0 && !$this->client->supports(RendererSessionConfig::CANVAS_IMAGE_TONE)) {
        throw new RendererProtocolException('Canvas image brightness requires negotiated canvas_image_tone support.');
      }
      if ($image->sourceRect !== null && !$this->client->supports(RendererSessionConfig::SPRITE_SOURCE_RECT)) {
        throw new RendererProtocolException('Canvas image crops require negotiated sprite_source_rect support.');
      }
    }
    $compositing = array_any($canvas->images, static fn($image) => $image->clipRect !== null)
      || array_any($canvas->textLayers, static fn($text) => $text->clipRect !== null || $text->opacity !== 1.0)
      || array_any($canvas->labels, static fn($label) => $label->clipRect !== null || $label->opacity !== 1.0);
    if ($compositing && !$this->client->supports(RendererSessionConfig::CANVAS_CLIP_OPACITY)) {
      throw new RendererProtocolException('Canvas clipping and text opacity require negotiated canvas_clip_opacity support.');
    }
    if (array_any($canvas->textLayers, static fn($text) => $text->glyphEffects !== null)
      && !$this->client->supports(RendererSessionConfig::CANVAS_GLYPH_EFFECTS)) {
      throw new RendererProtocolException('Canvas glyph contours require negotiated canvas_glyph_effects support.');
    }
  }

  /** @param list<PresentationWorldCanvas> $groups */
  private function validateWorldCanvases(array $groups): void
  {
    $groups = PresentationWorldCanvas::getOrderedList($groups);
    if ($groups !== [] && (!$this->client->supports(RendererSessionConfig::WORLD_CANVAS)
      || !$this->client->supports(RendererSessionConfig::FRAME_VIEWPORT))) {
      throw new RendererProtocolException('Field canvases require negotiated world_canvas and frame_viewport support.');
    }
    foreach ($groups as $group) { $this->validateCanvas($group->canvas); }
    CanvasFrameBudget::assertCanvases(array_map(static fn($group) => $group->canvas, $groups));
  }
}
