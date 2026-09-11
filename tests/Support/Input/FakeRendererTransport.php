<?php

namespace Tests\Support\Input;

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererTransportState;

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
  public ?RendererTransportException $failure = null;
  public ?RendererTransportException $sendFailure = null;

  public function start(RendererSessionConfig $session): void
  {
    $this->session = $session;
    $this->running = true;
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
  }

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
