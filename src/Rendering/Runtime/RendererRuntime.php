<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePageLayout;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasOverlayProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedFrame;
use Ichiloto\Engine\Rendering\ScreenTransitionPhase;
use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;
use Ichiloto\Engine\Rendering\RendererClient;
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
  private readonly SceneFrameComposer $frameComposer;
  private ?InputSourceInterface $previousInput = null;
  private ?RendererPresentation $presentation = null;
  private ?RendererSessionConfig $session = null;
  private ?NotificationManager $notificationManager = null;
  private bool $started = false;
  private bool $closed = false;
  private bool $closeRequested = false;
  private bool $resetText = true;
  private ?ScreenTransitionTreatment $handoffTreatment = null;
  private ?RetainedFrame $handoffFrame = null;
  private ?RetainedFrame $sceneFrame = null;
  private ScreenTransitionPhase $handoffPhase = ScreenTransitionPhase::GATHER;
  private float $handoffProgress = 0;
  public private(set) bool $windowActive = true;
  public private(set) ?RendererGridConfig $grid = null;

  public function __construct(private readonly RendererRuntimeConfig $config, ?RendererTransportInterface $transport = null)
  {
    $this->transport = $transport ?? new ProcessRendererTransport($config->process);
    $this->client = new RendererClient($this->transport);
    $this->input = new RendererInputSource($this->client);
    $this->frameComposer = new SceneFrameComposer($config->assetRoot);
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
    if ($notifications !== null) { $this->setNotificationManager($notifications); }
    $notifications ??= $this->notificationManager;
    $started = LatencyTrace::getTimeNow();
    LatencyTrace::record('presentation.begin', ['scene' => $scene === null ? null : $scene::class]);
    $this->pump();
    if ($this->presentation === null || $this->closed) {
      throw new LogicException('Renderer presentation requires an active session.');
    }
    if ($scene instanceof CanvasOverlayProviderInterface && $this->supports(RendererSessionConfig::CANVAS_OVERLAY)) {
      $scene->renderPresentationOverlay();
    }
    if ($this->handoffTreatment !== null && $this->handoffFrame !== null) {
      $changed = $this->presentHandoffFrame($notifications);
      if ($changed) { $this->pump(); }
      LatencyTrace::end('presentation.end', $started, ['changed' => $changed]);
      return $changed;
    }
    try {
      $composition = $this->frameComposer->composeFrame($scene, $this->grid, $this->supports(...),
        fn(array $excluded, array $worldLayers) => Console::getRetainedPresentationChanges($excluded, $this->resetText, $worldLayers),
        $notifications, refreshOverlay: false);
      $this->resetText = $composition->snapshot === null;
      $this->sceneFrame = $composition->prepareFrame($this->presentation);
      if ($this->handoffTreatment !== null) {
        $this->handoffFrame = $this->sceneFrame;
        $changed = $this->presentHandoffFrame($notifications);
      } else {
        $changed = $this->presentation->presentFrame($this->sceneFrame, $composition->screenOverlay);
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
    $this->handoffFrame = $this->sceneFrame ?? $this->presentation->captureFrame();
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

  private function presentHandoffFrame(?NotificationManager $notifications): bool
  {
    $frame = $this->handoffFrame ?? throw new LogicException('The handoff composition is not prepared.');
    $width = $frame->canvas?->width ?? $this->grid->columns * $this->grid->cellWidth;
    $height = $frame->canvas?->height ?? $this->grid->rows * $this->grid->cellHeight;
    // Capture only the scene. Notices remain live above the cover throughout the handoff.
    $cover = $this->handoffTreatment->compose($this->handoffPhase, $this->handoffProgress, $width, $height);
    $overlay = $notifications?->composePresentation($cover, $width, $height) ?? $cover;
    return $this->presentation->presentFrame($frame, $overlay);
  }

  /** Negotiate pages before typing begins; the renderer never advances dialogue. */
  public function getDialoguePageLayout(string $speaker, DialogueContext $context, string $help): ?DialoguePageLayout
  {
    if ($this->grid === null || !$this->supports(RendererSessionConfig::GRAPHICAL_CANVAS)) { return null; }
    // Pages must fit both the field viewport and the standard opaque menu canvas.
    $width = min($this->grid->columns * $this->grid->cellWidth,
      PresentationCanvas::DEFAULT_WIDTH);
    return $this->frameComposer->getDialoguePageLayout($speaker, $context, $help, $width);
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

  /** Attach the game-owned queue once; scene and transition callers need not forward it. */
  public function setNotificationManager(NotificationManager $notifications): void
  {
    $this->notificationManager = $notifications;
  }

  public function supports(string $capability): bool { return $this->client->supports($capability); }
}
