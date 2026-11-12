<?php

namespace Tests\Support\Input;

use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
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
  /** A responsive renderer consumes every queued frame packet and acknowledges it on the next poll. */
  public bool $acknowledgeFrames = false;
  /** @var list<RendererEvent> */
  private array $acknowledgements = [];

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
    if ($this->acknowledgeFrames && $message->type === RendererMessageType::FRAME) {
      $this->acknowledgements[] = RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'frame_ack',
        'generation' => $message->payload['generation'], 'frame' => $message->payload['frame'],
        'presented' => $message->payload['present']], JSON_THROW_ON_ERROR));
    }
    ($this->onSend)?->__invoke($this, $message);
  }

  /** The renderer consumed the latest queued frame packet; for presenters used without Runtime feedback. */
  public function acknowledgeLatestFrame(RetainedPresentation|RendererPresentation $presenter): void
  {
    for ($index = count($this->sent) - 1; $index >= 0; $index--) {
      if ($this->sent[$index]->type === RendererMessageType::FRAME) {
        $presenter->acknowledge($this->sent[$index]->payload['generation'], $this->sent[$index]->payload['present']);
        return;
      }
    }
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
    $batch = [...(array_shift($this->batches) ?? []), ...$this->acknowledgements];
    $this->acknowledgements = [];
    return $batch;
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
