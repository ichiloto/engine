<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
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

  public function acknowledge(int $generation, bool $presented): void { $this->retained->acknowledge($generation, $presented); }

  public function captureFrame(): RetainedFrame { return $this->retained->captureFrame(); }

  public function prepareFrame(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot, array $sprites = [],
    ?PresentationViewport $viewport = null, ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null): RetainedFrame
  {
    return $this->submitPresentation($snapshot, $sprites, [], $viewport, $world, $canvasOverlay, true);
  }

  public function prepareCanvas(PresentationCanvas $canvas): RetainedFrame
  {
    $this->validateCanvas($canvas);
    return $this->retained->prepareCanvas($canvas);
  }

  public function presentFrame(RetainedFrame $frame, ?PresentationCanvas $screenOverlay = null): bool
  {
    if ($screenOverlay !== null) {
      $this->validateCanvas($screenOverlay);
      if ($frame->canvasIsOverlay && (!$this->client->supports(RendererSessionConfig::CANVAS_OVERLAY)
        || $screenOverlay->width !== $this->grid->columns * $this->grid->cellWidth
        || $screenOverlay->height !== $this->grid->rows * $this->grid->cellHeight)) {
        throw new RendererProtocolException('Retained screen overlays require canvas_overlay and the session logical surface.');
      }
    }
    return $this->retained->presentFrame($frame, $screenOverlay);
  }

  /**
   * @param list<PresentationSprite> $sprites
   * @param list<PresentationTileBatch> $tileBatches
   * Returns true when retained-update bytes were queued, including a staged upload.
   */
  public function present(ConsoleFrameSnapshot|ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites = [], array $tileBatches = [], ?PresentationViewport $viewport = null,
    ?PresentationWorld $world = null, ?PresentationCanvas $canvasOverlay = null): bool
  {
    return $this->submitPresentation($snapshot, $sprites, $tileBatches, $viewport, $world, $canvasOverlay, false);
  }

  private function submitPresentation(ConsoleFrameSnapshot|ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot,
    array $sprites, array $tileBatches, ?PresentationViewport $viewport,
    ?PresentationWorld $world, ?PresentationCanvas $canvasOverlay, bool $prepare): bool|RetainedFrame
  {
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
      $this->validateCanvas($canvasOverlay);
    }
    return $prepare ? $this->retained->prepareFrame($snapshot, $sprites, $viewport, $world, $canvasOverlay)
      : $this->retained->present($snapshot, $sprites, $viewport, $world, $canvasOverlay);
  }

  /** Graphical frames do not require a Console snapshot or use its cell dimensions. */
  public function presentCanvas(PresentationCanvas $canvas): bool
  {
    $this->validateCanvas($canvas);
    return $this->retained->presentCanvas($canvas);
  }

  private function validateCanvas(PresentationCanvas $canvas): void
  {
    if (!$this->client->supports(RendererSessionConfig::GRAPHICAL_CANVAS)) {
      throw new RendererProtocolException('Canvas presentation requires negotiated graphical_canvas support.');
    }
    if ($canvas->composites !== [] && !$this->client->supports(RendererSessionConfig::CANVAS_COMPOSITING)) {
      throw new RendererProtocolException('Canvas raster operations require negotiated canvas_compositing support.');
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
      || array_any($canvas->textLayers, static fn($text) => $text->clipRect !== null || $text->opacity !== 1.0);
    if ($compositing && !$this->client->supports(RendererSessionConfig::CANVAS_CLIP_OPACITY)) {
      throw new RendererProtocolException('Canvas clipping and text opacity require negotiated canvas_clip_opacity support.');
    }
    if (array_any($canvas->textLayers, static fn($text) => $text->glyphEffects !== null)
      && !$this->client->supports(RendererSessionConfig::CANVAS_GLYPH_EFFECTS)) {
      throw new RendererProtocolException('Canvas glyph contours require negotiated canvas_glyph_effects support.');
    }
  }
}
