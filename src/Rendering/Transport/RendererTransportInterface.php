<?php

namespace Ichiloto\Engine\Rendering\Transport;

interface RendererTransportInterface
{
  public function start(RendererSessionConfig $session): void;
  public function isRunning(): bool;
  public function send(RendererMessage $message): void;

  /**
   * Service bounded I/O; zero wait by default. Continue pumping after OS exit to drain final events.
   * @return list<RendererEvent>
   */
  public function pollEvents(float $waitSeconds = 0.0): array;

  /** Graceful shutdown with bounded forced cleanup; returns the known exit code. */
  public function shutdown(): ?int;
  public function getState(): RendererTransportState;
  public function getExitCode(): ?int;
  public function getDiagnostics(): string;
}
