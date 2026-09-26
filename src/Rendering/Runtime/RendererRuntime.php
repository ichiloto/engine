<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\FrameViewportProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
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
  private bool $started = false;
  private bool $closed = false;
  private bool $closeRequested = false;
  private bool $resetText = true;
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

  public function present(?SceneInterface $scene): bool
  {
    $started = LatencyTrace::getTimeNow();
    LatencyTrace::record('presentation.begin', ['scene' => $scene === null ? null : $scene::class]);
    $this->pump();
    if ($this->presentation === null || $this->closed) {
      throw new LogicException('Renderer presentation requires an active session.');
    }
    $canvas = $scene instanceof CanvasProviderInterface ? $scene->getPresentationCanvas() : null;
    if ($canvas !== null) {
      $changed = $this->presentation->presentCanvas($canvas);
      $this->resetText = true;
      if ($changed) { $this->pump(); }
      LatencyTrace::end('presentation.end', $started, ['changed' => $changed]);
      return $changed;
    }
    $collection = LatencyTrace::getTimeNow();
    $sprites = $this->collector->collect($scene);
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
    $world = $scene instanceof RetainedWorldProviderInterface ? $scene->getPresentationWorld() : null;
    $snapshotStart = LatencyTrace::getTimeNow();
    $snapshot = Console::getRetainedPresentationChanges($excluded, $this->resetText, $world?->textLayerIds ?? []);
    LatencyTrace::end('presentation.snapshot', $snapshotStart);
    $viewport = $scene instanceof FrameViewportProviderInterface
      ? $scene->getPresentationViewport($snapshot, $sprites, []) : null;
    $this->resetText = false;
    try {
      $changed = $this->presentation->present($snapshot, $sprites, viewport: $viewport, world: $world);
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

  public function shutdown(): ?int
  {
    if ($this->closed) {
      return $this->transport->getExitCode();
    }
    $this->closed = true;
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
