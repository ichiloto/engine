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
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;

/** Shared frame assembly; providers still own projection, clocks, map identity and cleanup. */
final class SceneFrameComposer
{
  private readonly GraphicalSpriteCollector $collector;
  private readonly DialogueScenePresentation $dialogue;
  /** Where a graphical field reports a subject it can only show by its terminal glyph. */
  public readonly PresentationFallbackReport $fallbacks;

  public function __construct(string $assetRoot)
  {
    $this->collector = new GraphicalSpriteCollector();
    $this->fallbacks = new PresentationFallbackReport();
    $this->dialogue = new DialogueScenePresentation($assetRoot);
  }

  /**
   * The text consumer owns capture: incremental changes at runtime, a full snapshot or reset changes in preview.
   * It must apply both exclusion lists in its own isolated Console context; this composer never consumes a cursor.
   * @param Closure(string): bool $supports
   * @param Closure(list<string>, list<string>): (ConsolePresentationSnapshot|ConsolePresentationChanges) $collectText
   * Runtime refreshes Terminal overlays before cached handoffs; isolated previews refresh them here.
   */
  public function composeFrame(SceneInterface|FramePresentationSourceInterface|null $scene, RendererGridConfig $grid, Closure $supports,
    Closure $collectText, ?NotificationManager $notifications = null, bool $refreshOverlay = true): SceneFrameComposition
  {
    return PngAssetPreflight::runWithSnapshot(fn() => $this->composeSnapshot($scene, $grid, $supports,
      $collectText, $notifications, $refreshOverlay));
  }

  private function composeSnapshot(SceneInterface|FramePresentationSourceInterface|null $scene, RendererGridConfig $grid, Closure $supports,
    Closure $collectText, ?NotificationManager $notifications, bool $refreshOverlay): SceneFrameComposition
  {
    $overlaySupported = $supports(RendererSessionConfig::CANVAS_OVERLAY);
    if ($refreshOverlay && $scene instanceof CanvasOverlayProviderInterface && $overlaySupported) {
      $scene->renderPresentationOverlay();
    }
    $width = $grid->columns * $grid->cellWidth;
    $height = $grid->rows * $grid->cellHeight;
    $canvas = $scene instanceof CanvasProviderInterface ? $scene->getPresentationCanvas() : null;
    $dialogue = $scene instanceof SceneInterface ? $this->dialogue->compose($scene, $canvas, $width, $height,
      $overlaySupported && $supports(RendererSessionConfig::GRAPHICAL_CANVAS)
        && $supports(RendererSessionConfig::CANVAS_CLIP_OPACITY) && $supports(RendererSessionConfig::SPRITE_SOURCE_RECT),
      $supports(RendererSessionConfig::GRAPHICAL_CANVAS) && $supports(RendererSessionConfig::CANVAS_CLIP_OPACITY)
        && $supports(RendererSessionConfig::SPRITE_SOURCE_RECT), $supports(RendererSessionConfig::CANVAS_IMAGE_TONE)) : null;
    $canvas = $dialogue !== null ? $dialogue->canvas : $canvas;
    if ($canvas !== null && !($dialogue?->isOverlay ?? false)) {
      $sceneOverlay = $scene instanceof CanvasOverlayProviderInterface && $overlaySupported
        ? $scene->getPresentationOverlay($canvas->width, $canvas->height) : null;
      $noticeCanvas = $notifications?->composePresentation(null, $canvas->width, $canvas->height);
      $references = [...$canvas->getReferencedWorldIds(), ...($sceneOverlay?->getReferencedWorldIds() ?? []),
        ...($noticeCanvas?->getReferencedWorldIds() ?? [])];
      $world = $references !== [] && $scene instanceof RetainedWorldProviderInterface ? $scene->getPresentationWorld() : null;
      return new SceneFrameComposition(null, world: $world, canvas: $canvas, screenOverlay: $noticeCanvas === null ? $sceneOverlay
        : PresentationCanvas::composeOverlay($sceneOverlay, $noticeCanvas));
    }
    $collection = LatencyTrace::getTimeNow();
    $unpresented = [];
    if ($scene instanceof SceneInterface || $scene === null) {
      ['sprites' => $sprites, 'unpresented' => $unpresented] = $this->collector->collectPresentation($scene);
    } else {
      $sprites = PresentationSprite::orderedList($scene->getPresentationSprites());
    }
    $slides = $supports(RendererSessionConfig::FIELD_MOTION);
    if (!$slides) { $sprites = array_map(static fn($sprite) => $sprite->withoutMotion(), $sprites); }
    LatencyTrace::end('presentation.sprites', $collection, ['count' => count($sprites)]);
    if (LatencyTrace::enabled()) {
      LatencyTrace::record('presentation.sprite.positions', ['sprites' => array_map(
        static fn($sprite) => ['id' => $sprite->id, 'x' => $sprite->x, 'y' => $sprite->y,
          'sourceRect' => $sprite->sourceRect?->toArray()], $sprites)]);
    }
    PresentationLayerPolicy::assertWorldSprites($sprites);
    // A sprite is its subject's graphical presentation wherever it stands: off-grid sprites stay for renderer
    // clipping, and their subject's terminal layer never shows in its place. A wide terminal glyph whose subject
    // stands just past the left edge would otherwise leave its tail on screen as a glyph.
    $excluded = array_map(static fn($sprite) => $sprite->id, $sprites);
    $sceneOverlay = $scene instanceof CanvasOverlayProviderInterface && $overlaySupported
      ? $scene->getPresentationOverlay($width, $height) : null;
    if ($scene instanceof CanvasOverlayProviderInterface && $overlaySupported) {
      array_push($excluded, ...$scene->getExcludedOverlayLayers());
    }
    if ($dialogue !== null) { array_push($excluded, ...$dialogue->excludedLayers); }
    $noticeCanvas = $notifications?->composePresentation(null, $width, $height);
    if ($notifications !== null) { array_push($excluded, ...$notifications->getExcludedPresentationLayers()); }
    $world = $scene instanceof RetainedWorldProviderInterface ? $scene->getPresentationWorld() : null;
    $snapshotStart = LatencyTrace::getTimeNow();
    $snapshot = $collectText(array_values($excluded), $world?->textLayerIds ?? []);
    LatencyTrace::end('presentation.snapshot', $snapshotStart);
    if ($world !== null) { $this->reportGlyphSubjects($unpresented, $snapshot); }
    $viewport = $scene instanceof FrameViewportProviderInterface
      ? $scene->getPresentationViewport($snapshot, $sprites, []) : null;
    if (!$slides) { $viewport = $viewport?->withoutFollow(); }
    $groups = $scene instanceof WorldCanvasProviderInterface ? $scene->getPresentationWorldCanvases() : [];
    if ($groups !== []) {
      if (!$supports(RendererSessionConfig::WORLD_CANVAS) || !$supports(RendererSessionConfig::FRAME_VIEWPORT)) {
        throw new RendererProtocolException('Field canvases require negotiated world_canvas and frame_viewport support.');
      }
      $groups = PresentationWorldCanvas::getOrderedList($groups);
      if ($viewport === null || $world === null || $viewport->worldId !== $world->id) {
        throw new RendererProtocolException('Field canvases require their selected field world and viewport.');
      }
      foreach ($groups as $group) {
        if (!PresentationLayerPolicy::isFieldWorldLayer($group->layer)) {
          throw new \InvalidArgumentException('Automatic Game world canvases require layers -1..999; guidance and UI layers are reserved.');
        }
      }
      $viewport = $viewport->selectWorldCanvases(array_column($groups, 'id'));
    }
    $overlay = $dialogue?->isOverlay ? $dialogue->canvas : null;
    return new SceneFrameComposition($snapshot, $sprites, $viewport, $world, $overlay,
      $noticeCanvas === null ? $sceneOverlay : PresentationCanvas::composeOverlay($sceneOverlay, $noticeCanvas), $groups);
  }

  public function getDialoguePageLayout(string $speaker, DialogueContext $context, string $help, int $width): ?DialoguePageLayout
  {
    return $this->dialogue->getPageLayout($speaker, $context, $help, $width);
  }

  /**
   * Reports each field subject the graphical field shows by its terminal
   * glyph because it has no sprite: its own terminal layer is drawn with
   * visible glyphs this frame. Graceful degradation keeps play going; the
   * report keeps the missing presentation from passing unnoticed.
   *
   * @param list<string> $unpresented Subject ids presented this frame without a sprite.
   */
  private function reportGlyphSubjects(array $unpresented, ConsolePresentationSnapshot|ConsolePresentationChanges $text): void
  {
    if ($unpresented === []) { return; }
    $subjects = array_fill_keys($unpresented, true);
    $layers = $text instanceof ConsolePresentationSnapshot
      ? array_map(static fn(PresentationTextLayer $layer): array => [$layer->id, $layer->runs], $text->textLayers)
      : array_map(static fn(array $layer): array => [$layer['id'], array_merge(...array_column($layer['rows'], 'runs'))],
        $text->layers);
    foreach ($layers as [$id, $runs]) {
      if (!isset($subjects[$id])) { continue; }
      $glyphs = trim(implode('', array_map(static fn(PresentationTextRun $run): string => $run->text, $runs)));
      if ($glyphs === '') { continue; }
      $this->fallbacks->report(sprintf("Field subject '%s' has no graphical sprite; the graphical field draws its terminal glyph '%s' instead.",
        $id, $glyphs));
    }
  }
}
