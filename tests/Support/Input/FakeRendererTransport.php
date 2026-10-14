<?php

namespace Tests\Support\Input;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererTransportState;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Interfaces\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

final class FakeRendererTransport implements RendererTransportInterface
{
  /** @var list<list<RendererEvent>> */
  public array $batches = [];
  /** @var list<RendererMessage> */
  public array $sent = [];
  public ?RendererSessionConfig $session = null;
  public bool $running = false;
  public int $polls = 0;
  public int $shutdowns = 0;
  public int $starts = 0;
  public ?\Closure $onStart = null;
  public ?\Closure $onSend = null;
  public ?RendererTransportException $failure = null;
  public ?RendererTransportException $sendFailure = null;
  public ?RendererTransportException $shutdownFailure = null;
  public bool $acceptWrites = true;
  public int $pendingWriteBytes = 0;

  public function start(RendererSessionConfig $session): void
  {
    $this->session = $session;
    $this->running = true;
    $this->starts++;
    ($this->onStart)?->__invoke($this);
  }

  public function isRunning(): bool
  {
    return $this->running;
  }

  public function send(RendererMessage $message): void
  {
    if ($this->sendFailure !== null) {
      throw $this->sendFailure;
    }
    $this->sent[] = $message;
    ($this->onSend)?->__invoke($this, $message);
  }

  public function trySend(RendererMessage $message): bool
  {
    if (!$this->acceptWrites) { return false; }
    $this->send($message);
    return true;
  }

  public function getPendingWriteBytes(): int { return $this->pendingWriteBytes; }

  public function pollEvents(float $waitSeconds = 0.0): array
  {
    if ($waitSeconds !== 0.0) {
      throw new \LogicException('The client must only pump zero-wait I/O.');
    }
    $this->polls++;
    if ($this->failure !== null) {
      throw $this->failure;
    }
    return array_shift($this->batches) ?? [];
  }

  public function shutdown(): ?int
  {
    $this->shutdowns++;
    $this->running = false;
    if ($this->shutdownFailure !== null) { throw $this->shutdownFailure; }
    return 0;
  }

  public function getState(): RendererTransportState
  {
    return $this->running ? RendererTransportState::RUNNING : RendererTransportState::STOPPED;
  }

  public function getExitCode(): ?int
  {
    return $this->running ? null : 0;
  }

  public function getDiagnostics(): string
  {
    return 'fixture diagnostics';
  }
}
