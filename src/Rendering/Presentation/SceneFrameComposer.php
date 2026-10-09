<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePageLayout;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasOverlayProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/** Session-local scene assembly; providers still own projection, clocks, map identity and cleanup. */
final class SceneFrameComposer
{
  private readonly GraphicalSpriteCollector $collector;
  private readonly DialogueScenePresentation $dialogue;

  public function __construct(string $assetRoot)
  {
    $this->collector = new GraphicalSpriteCollector();
    $this->dialogue = new DialogueScenePresentation($assetRoot);
  }

  /**
   * The text consumer owns capture: incremental changes at runtime, a full snapshot or reset changes in preview.
   * It must apply both exclusion lists in its own isolated Console context; this composer never consumes a cursor.
   * @param Closure(string): bool $supports
   * @param Closure(list<string>, list<string>): (ConsolePresentationSnapshot|ConsolePresentationChanges) $collectText
   * Runtime refreshes Terminal overlays before cached handoffs; isolated previews refresh them here.
   */
  public function composeFrame(?SceneInterface $scene, RendererGridConfig $grid, Closure $supports,
    Closure $collectText, ?NotificationManager $notifications = null, bool $refreshOverlay = true): SceneFrameComposition
  {
    $overlaySupported = $supports(RendererSessionConfig::CANVAS_OVERLAY);
    if ($refreshOverlay && $scene instanceof CanvasOverlayProviderInterface && $overlaySupported) {
      $scene->renderPresentationOverlay();
    }
    $width = $grid->columns * $grid->cellWidth;
    $height = $grid->rows * $grid->cellHeight;
    $canvas = $scene instanceof CanvasProviderInterface ? $scene->getPresentationCanvas() : null;
    $dialogue = $scene === null ? null : $this->dialogue->compose($scene, $canvas, $width, $height,
      $overlaySupported && $supports(RendererSessionConfig::GRAPHICAL_CANVAS)
        && $supports(RendererSessionConfig::CANVAS_CLIP_OPACITY) && $supports(RendererSessionConfig::SPRITE_SOURCE_RECT),
      $supports(RendererSessionConfig::GRAPHICAL_CANVAS) && $supports(RendererSessionConfig::CANVAS_CLIP_OPACITY)
        && $supports(RendererSessionConfig::SPRITE_SOURCE_RECT), $supports(RendererSessionConfig::CANVAS_IMAGE_TONE));
    $canvas = $dialogue !== null ? $dialogue->canvas : $canvas;
    if ($canvas !== null && !($dialogue?->isOverlay ?? false)) {
      $sceneOverlay = $scene instanceof CanvasOverlayProviderInterface && $overlaySupported
        ? $scene->getPresentationOverlay($canvas->width, $canvas->height) : null;
      $noticeCanvas = $notifications?->composePresentation(null, $canvas->width, $canvas->height);
      return new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $noticeCanvas === null ? $sceneOverlay
        : PresentationCanvas::composeOverlay($sceneOverlay, $noticeCanvas));
    }
    $collection = LatencyTrace::getTimeNow();
    $sprites = $this->collector->collect($scene);
    $slides = $supports(RendererSessionConfig::FIELD_MOTION);
    if (!$slides) { $sprites = array_map(static fn($sprite) => $sprite->withoutMotion(), $sprites); }
    LatencyTrace::end('presentation.sprites', $collection, ['count' => count($sprites)]);
    if (LatencyTrace::enabled()) {
      LatencyTrace::record('presentation.sprite.positions', ['sprites' => array_map(
        static fn($sprite) => ['id' => $sprite->id, 'x' => $sprite->x, 'y' => $sprite->y,
          'sourceRect' => $sprite->sourceRect?->toArray()], $sprites)]);
    }
    PresentationLayerPolicy::assertWorldSprites($sprites);
    // Keep off-grid sprites for renderer clipping without concealing Terminal edge cells.
    $visible = array_filter($sprites, static fn($sprite) => $sprite->x >= 0 && $sprite->x < $grid->columns
      && $sprite->y >= 0 && $sprite->y < $grid->rows);
    $excluded = array_map(static fn($sprite) => $sprite->id, $visible);
    $sceneOverlay = $scene instanceof CanvasOverlayProviderInterface && $overlaySupported
      ? $scene->getPresentationOverlay($width, $height) : null;
    if ($sceneOverlay !== null) { array_push($excluded, ...$scene->getExcludedOverlayLayers()); }
    if ($dialogue !== null) { array_push($excluded, ...$dialogue->excludedLayers); }
    $noticeCanvas = $notifications?->composePresentation(null, $width, $height);
    if ($notifications !== null) { array_push($excluded, ...$notifications->getExcludedPresentationLayers()); }
    $world = $scene instanceof RetainedWorldProviderInterface ? $scene->getPresentationWorld() : null;
    $snapshotStart = LatencyTrace::getTimeNow();
    $snapshot = $collectText(array_values($excluded), $world?->textLayerIds ?? []);
    LatencyTrace::end('presentation.snapshot', $snapshotStart);
    $viewport = $scene instanceof FrameViewportProviderInterface
      ? $scene->getPresentationViewport($snapshot, $sprites, []) : null;
    if (!$slides) { $viewport = $viewport?->withoutFollow(); }
    $overlay = $dialogue?->isOverlay ? $dialogue->canvas : null;
    return new SceneFrameComposition($snapshot, $sprites, $viewport, $world, $overlay,
      $noticeCanvas === null ? $sceneOverlay : PresentationCanvas::composeOverlay($sceneOverlay, $noticeCanvas));
  }

  public function getDialoguePageLayout(string $speaker, DialogueContext $context, string $help, int $width): ?DialoguePageLayout
  {
    return $this->dialogue->getPageLayout($speaker, $context, $help, $width);
  }
}
