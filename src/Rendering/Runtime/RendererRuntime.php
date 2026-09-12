<?php

namespace Ichiloto\Engine\Rendering\Runtime;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererTransportInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use LogicException;
use Throwable;

/** Owns one explicit session; PHP Game owns simulation and frame cadence. */
final class RendererRuntime
{
  private readonly RendererTransportInterface $transport;
  private readonly RendererClient $client;
  private readonly RendererInputSource $input;
  private readonly GraphicalSpriteCollector $collector;
  private ?InputSourceInterface $previousInput = null;
  private ?RendererPresentation $presentation = null;
  private bool $started = false;
  private bool $closed = false;
  private bool $closeRequested = false;

  public function __construct(private readonly RendererRuntimeConfig $config, ?RendererTransportInterface $transport = null)
  {
    $this->transport = $transport ?? new ProcessRendererTransport($config->process);
    $this->client = new RendererClient($this->transport);
    $this->input = new RendererInputSource($this->client);
    $this->collector = new GraphicalSpriteCollector();
  }

  public function start(string $title, int $columns, int $rows): void
  {
    if ($this->started || $this->closed) {
      throw new LogicException('A RendererRuntime owns exactly one session.');
    }
    $grid = new RendererGridConfig($columns, $rows, $this->config->cellWidth, $this->config->cellHeight);
    $session = new RendererSessionConfig($title, $this->config->assetRoot, $grid, $this->config->protocol,
      $this->config->requiredCapabilities);
    $this->started = true;
    try {
      $this->client->start($session);
      $this->presentation = new RendererPresentation($this->client, $grid);
      $this->previousInput = InputManager::getInputSource();
      InputManager::setInputSource($this->input);
      Console::setLayerTracking(true);
      $this->pump();
    } catch (Throwable $error) {
      try { $this->shutdown(); } catch (Throwable) { /* Preserve the startup failure. */ }
      throw $error;
    }
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
    foreach ($this->client->drainEvents() as $event) {
      if ($event->type === RendererEventType::CLOSE_REQUESTED) {
        $this->closeRequested = true;
      } elseif ($event->type === RendererEventType::ERROR) {
        throw new RendererTransportException('Renderer error: ' . $event->message,
          $this->transport->getDiagnostics(), $this->transport->getExitCode());
      }
    }
    if ($this->closeRequested) {
      throw new RendererWindowClosed('Renderer window closed.');
    }
  }

  public function present(?SceneInterface $scene): bool
  {
    $started = LatencyTrace::now();
    LatencyTrace::record('presentation.begin', ['scene' => $scene === null ? null : $scene::class]);
    $this->pump();
    if ($this->presentation === null || $this->closed) {
      throw new LogicException('Renderer presentation requires an active session.');
    }
    $collection = LatencyTrace::now();
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
    $snapshotStart = LatencyTrace::now();
    $snapshot = $this->config->protocol === RendererProtocolVersion::V2
      ? Console::presentationSnapshot($excluded) : Console::snapshot($excluded);
    LatencyTrace::end('presentation.snapshot', $snapshotStart);
    $changed = $this->presentation->present($snapshot, $sprites);
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
    }
    return $this->client->shutdown();
  }
}
