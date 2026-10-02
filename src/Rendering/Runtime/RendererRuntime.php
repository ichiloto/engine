<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\FrameViewportProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedFrame;
use Ichiloto\Engine\Rendering\ScreenTransitionPhase;
use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldProviderInterface;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Interfaces\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use LogicException;
use Throwable;
use Ichiloto\Engine\Util\Debug;

/** Owns one explicit session; PHP Game owns simulation and frame cadence. */
final class RendererRuntime
{
  private readonly RendererTransportInterface $transport;
  private readonly RendererClient $client;
  private readonly RendererInputSource $input;
  private readonly GraphicalSpriteCollector $collector;
  private ?InputSourceInterface $previousInput = null;
  private ?RendererPresentation $presentation = null;
  private ?RendererSessionConfig $session = null;
  private ?DialogueScenePresentation $dialoguePresentation = null;
  private bool $started = false;
  private bool $closed = false;
  private bool $closeRequested = false;
  private bool $resetText = true;
  private ?ScreenTransitionTreatment $handoffTreatment = null;
  private ?RetainedFrame $handoffFrame = null;
  private ScreenTransitionPhase $handoffPhase = ScreenTransitionPhase::GATHER;
  private float $handoffProgress = 0;
  public private(set) bool $windowActive = true;
  public private(set) ?RendererGridConfig $grid = null;

  public function __construct(private readonly RendererRuntimeConfig $config, ?RendererTransportInterface $transport = null)
  {
    $this->transport = $transport ?? new ProcessRendererTransport($config->process);
    $this->client = new RendererClient($this->transport);
    $this->input = new RendererInputSource($this->client);
    $this->collector = new GraphicalSpriteCollector();
  }

  /** @param string|null $icon The game's application icon, relative to the asset root. */
  public function start(string $title, int $columns, int $rows, ?string $icon = null): void
  {
    if ($this->started || $this->closed) {
      throw new LogicException('A RendererRuntime owns exactly one session.');
    }
    $grid = new RendererGridConfig($columns, $rows, $this->config->cellWidth, $this->config->cellHeight);
    $this->grid = $grid;
    $session = new RendererSessionConfig($title, $this->config->assetRoot, $grid, $this->config->protocol,
      $this->config->requiredCapabilities, $icon);
    $this->session = $session;
    $this->started = true;
    try {
      $this->client->start($session);
      $this->presentation = new RendererPresentation($this->client, $grid, $this->pump(...));
      $this->previousInput = InputManager::getInputSource();
      InputManager::setInputSource($this->input);
      Console::setLayerTracking(true);
      Console::setRetainedWorldPresentation(true);
      $this->pump();
    } catch (Throwable $error) {
      try { $this->shutdown(); } catch (Throwable) { /* Preserve the startup failure. */ }
      throw $error;
    }
  }

  /** Restart the native surface without replacing gameplay, Console or desired presentation. */
  public function restart(): void
  {
    if (!$this->started || $this->closed || $this->session === null) {
      throw new LogicException('Renderer restart requires an active runtime.');
    }
    try { $this->client->shutdown(); }
    catch (RendererTransportException $error) {
      if ($this->client->isRunning()) { throw $error; }
      Debug::warn('Renderer restart recovered a stopped peer: ' . $error->getMessage());
    }
    finally {
      $this->client->drainEvents();
      $this->client->resetKeys();
      // The new surface has seen no presses; nothing may stay held from the old one.
      if (InputManager::getInputSource() === $this->input) { InputManager::resetState(); }
      $this->presentation?->invalidate(newSession: true);
      $this->resetText = true;
      $this->closeRequested = false;
      $this->windowActive = true;
    }
    $this->client->start($this->session);
    $this->pump();
  }

  public function pump(): void
  {
    if ($this->closeRequested) {
      throw new RendererWindowClosed('Renderer window closed.');
    }
    if (!$this->started || $this->closed) {
      return;
    }
    $this->client->pump();
    $invalidate = false;
    $expectedGeneration = null;
    foreach ($this->client->drainEvents() as $event) {
      if ($event->type === RendererEventType::CLOSE_REQUESTED) {
        $this->closeRequested = true;
      } elseif ($event->type === RendererEventType::WINDOW_ACTIVATION) {
        $this->windowActive = $event->active ?? throw new LogicException('Window activation lacks its validated state.');
      } elseif ($event->type === RendererEventType::FRAME_REJECTED) {
        Debug::warn('Renderer rejected a presentation update: ' . $event->message . '. Retained state will be resynchronized.');
        $invalidate = true;
        $expectedGeneration = max($expectedGeneration ?? 0, $event->expectedGeneration ?? 0);
      } elseif ($event->type === RendererEventType::RESIZED) {
        $invalidate = true;
      } elseif ($event->type === RendererEventType::FRAME_ACK) {
        $this->presentation?->acknowledge($event->generation, $event->presented);
      } elseif ($event->type === RendererEventType::ERROR) {
        throw new RendererTransportException('Renderer error: ' . $event->message,
          $this->transport->getDiagnostics(), $this->transport->getExitCode());
      }
    }
    if ($invalidate) {
      $this->presentation?->invalidate(expectedGeneration: $expectedGeneration);
      $this->resetText = true;
    }
    if ($this->closeRequested) {
      throw new RendererWindowClosed('Renderer window closed.');
    }
  }

  public function present(?SceneInterface $scene, ?NotificationManager $notifications = null): bool
  {
    $started = LatencyTrace::getTimeNow();
    LatencyTrace::record('presentation.begin', ['scene' => $scene === null ? null : $scene::class]);
    $this->pump();
    if ($this->presentation === null || $this->closed) {
      throw new LogicException('Renderer presentation requires an active session.');
    }
    if ($this->handoffTreatment !== null && $this->handoffFrame !== null) {
      $changed = $this->presentHandoffFrame();
      if ($changed) { $this->pump(); }
      LatencyTrace::end('presentation.end', $started, ['changed' => $changed]);
      return $changed;
    }
    $canvas = $scene instanceof CanvasProviderInterface ? $scene->getPresentationCanvas() : null;
    $dialogue = $scene === null ? null : ($this->dialoguePresentation ??= new DialogueScenePresentation($this->config->assetRoot))
      ->compose($scene, $canvas, $this->grid->columns * $this->grid->cellWidth, $this->grid->rows * $this->grid->cellHeight,
        $this->supports(RendererSessionConfig::CANVAS_OVERLAY) && $this->supports(RendererSessionConfig::GRAPHICAL_CANVAS)
          && $this->supports(RendererSessionConfig::CANVAS_CLIP_OPACITY) && $this->supports(RendererSessionConfig::SPRITE_SOURCE_RECT),
        $this->supports(RendererSessionConfig::GRAPHICAL_CANVAS) && $this->supports(RendererSessionConfig::CANVAS_CLIP_OPACITY)
          && $this->supports(RendererSessionConfig::SPRITE_SOURCE_RECT),
        $this->supports(RendererSessionConfig::CANVAS_IMAGE_TONE));
    $canvas = $dialogue !== null ? $dialogue->canvas : $canvas;
    if ($canvas !== null && !($dialogue?->isOverlay ?? false)) {
      if ($notifications !== null) {
        $protected = NotificationPlacement::getProtectedAreas($canvas, null, [], $this->grid);
        $canvas = $notifications->composePresentation($canvas, $canvas->width, $canvas->height, $protected);
      }
    }
    if ($canvas !== null && !($dialogue?->isOverlay ?? false)) {
      if ($this->handoffTreatment !== null) {
        $this->handoffFrame = $this->presentation->prepareCanvas($canvas);
        $changed = $this->presentHandoffFrame();
      } else { $changed = $this->presentation->presentCanvas($canvas); }
      $this->resetText = true;
      if ($changed) { $this->pump(); }
      LatencyTrace::end('presentation.end', $started, ['changed' => $changed]);
      return $changed;
    }
    $collection = LatencyTrace::getTimeNow();
    $sprites = $this->collector->collect($scene);
    // Steps are committed either way; a renderer without field_motion places sprites by whole cells.
    $slides = $this->client->supports(RendererSessionConfig::FIELD_MOTION);
    if (!$slides) {
      $sprites = array_map(static fn($sprite) => $sprite->withoutMotion(), $sprites);
    }
    LatencyTrace::end('presentation.sprites', $collection, ['count' => count($sprites)]);
    if (LatencyTrace::enabled()) {
      LatencyTrace::record('presentation.sprite.positions', ['sprites' => array_map(
        static fn($sprite) => ['id' => $sprite->id, 'x' => $sprite->x, 'y' => $sprite->y,
          'sourceRect' => $sprite->sourceRect?->toArray()], $sprites)]);
    }
    PresentationLayerPolicy::assertWorldSprites($sprites);
    // Off-grid providers still reach GPUI for clipping, but do not mask terminal edge cells.
    $visible = array_filter($sprites, static fn($sprite) => $sprite->x >= 0 && $sprite->x < Console::getWidth()
      && $sprite->y >= 0 && $sprite->y < Console::getHeight());
    $excluded = array_map(static fn($sprite) => $sprite->id, $visible);
    if ($dialogue !== null) { array_push($excluded, ...$dialogue->excludedLayers); }
    if ($notifications !== null) { array_push($excluded, ...$notifications->getExcludedPresentationLayers()); }
    $world = $scene instanceof RetainedWorldProviderInterface ? $scene->getPresentationWorld() : null;
    $snapshotStart = LatencyTrace::getTimeNow();
    $snapshot = Console::getRetainedPresentationChanges($excluded, $this->resetText, $world?->textLayerIds ?? []);
    LatencyTrace::end('presentation.snapshot', $snapshotStart);
    $viewport = $scene instanceof FrameViewportProviderInterface
      ? $scene->getPresentationViewport($snapshot, $sprites, []) : null;
    if (!$slides) {
      $viewport = $viewport?->withoutFollow();
    }
    $overlay = $dialogue?->isOverlay ? $dialogue->canvas : null;
    if ($notifications !== null) {
      $protected = $notifications->hasGraphicalPresentation()
        ? NotificationPlacement::getProtectedAreas($overlay, Console::presentationSnapshot($excluded), $sprites, $this->grid, $viewport)
        : [];
      $overlay = $notifications->composePresentation($overlay,
        $this->grid->columns * $this->grid->cellWidth, $this->grid->rows * $this->grid->cellHeight, $protected);
    }
    $this->resetText = false;
    try {
      if ($this->handoffTreatment !== null) {
        $this->handoffFrame = $this->presentation->prepareFrame($snapshot, $sprites, $viewport, $world, $overlay);
        $changed = $this->presentHandoffFrame();
      } else {
        $changed = $this->presentation->present($snapshot, $sprites, viewport: $viewport, world: $world,
          canvasOverlay: $overlay);
      }
    } catch (Throwable $error) {
      $this->resetText = true;
      throw $error;
    }
    if ($changed) {
      // Begin delivery at the presentation boundary, not after Game/Timers sleep.
      // This is one bounded zero-wait pass; partial writes retain their remainder.
      $this->pump();
    }
    LatencyTrace::end('presentation.end', $started, ['changed' => $changed]);
    return $changed;
  }

  /** PHP's scene owner controls timing. This renderer only retains and paints the composition. */
  public function beginScreenHandoff(ScreenTransitionTreatment $treatment): void
  {
    if ($this->presentation === null || $this->closed || $this->handoffTreatment !== null) {
      throw new LogicException('A screen handoff requires one active, unowned renderer session.');
    }
    foreach ([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_COMPOSITING,
      RendererSessionConfig::CANVAS_OVERLAY] as $capability) {
      if (!$this->supports($capability)) { throw new LogicException('A screen handoff requires ' . $capability); }
    }
    $this->handoffFrame = $this->presentation->captureFrame();
    $this->handoffTreatment = $treatment;
    $this->handoffPhase = ScreenTransitionPhase::GATHER;
    $this->handoffProgress = 0;
  }

  public function setScreenHandoffPhase(ScreenTransitionPhase $phase, float $progress): void
  {
    if ($this->handoffTreatment === null) { throw new LogicException('No screen handoff owns the renderer.'); }
    $this->handoffPhase = $phase;
    $this->handoffProgress = $progress;
  }

  /** The next presentation captures the prepared incoming scene under the same full cover. */
  public function replaceScreenHandoff(): void
  {
    if ($this->handoffTreatment === null || $this->handoffPhase !== ScreenTransitionPhase::HOLD) {
      throw new LogicException('Only a fully covered screen handoff may replace its scene.');
    }
    $this->handoffFrame = null;
    $this->resetText = true;
  }

  public function endScreenHandoff(): void
  {
    $this->handoffTreatment = null;
    $this->handoffFrame = null;
    $this->resetText = true;
  }

  private function presentHandoffFrame(): bool
  {
    $frame = $this->handoffFrame ?? throw new LogicException('The handoff composition is not prepared.');
    $width = $frame->canvas?->width ?? $this->grid->columns * $this->grid->cellWidth;
    $height = $frame->canvas?->height ?? $this->grid->rows * $this->grid->cellHeight;
    return $this->presentation->presentFrame($frame,
      $this->handoffTreatment->compose($this->handoffPhase, $this->handoffProgress, $width, $height));
  }

  public function shutdown(): ?int
  {
    if ($this->closed) {
      return $this->transport->getExitCode();
    }
    $this->closed = true;
    $this->endScreenHandoff();
    if (!$this->started) {
      return null;
    }
    if ($this->previousInput !== null) {
      if (InputManager::getInputSource() === $this->input) {
        InputManager::setInputSource($this->previousInput);
      }
      Console::setLayerTracking(false);
      Console::setRetainedWorldPresentation(false);
    }
    return $this->client->shutdown();
  }

  public function getAssetRoot(): string { return $this->config->assetRoot; }

  public function supports(string $capability): bool { return $this->client->supports($capability); }
}
