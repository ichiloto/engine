<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererTransportInterface;
use InvalidArgumentException;
use SplQueue;

/** The sole event consumer for a shared renderer connection. No input/action mapping. */
final class RendererClient
{
  /** @var SplQueue<RendererEvent> */
  private SplQueue $keys;
  /** @var SplQueue<RendererEvent> */
  private SplQueue $events;
  private int $queuedBytes = 0;
  private ?RendererTransportException $failure = null;

  public function __construct(
    private readonly RendererTransportInterface $transport,
    private readonly int $maxPendingKeys = 1024,
    private readonly int $maxPendingEvents = 256,
    private readonly int $maxQueuedBytes = 8388608,
  )
  {
    if ($maxPendingKeys < 1 || $maxPendingKeys > 65536 || $maxPendingEvents < 1 || $maxPendingEvents > 65536
      || $maxQueuedBytes < 1 || $maxQueuedBytes > 33554432) {
      throw new InvalidArgumentException('Renderer client queue limits must be positive and within supported bounds.');
    }
    $this->keys = new SplQueue();
    $this->events = new SplQueue();
  }

  public function start(RendererSessionConfig $session): void
  {
    if (! $this->keys->isEmpty() || ! $this->events->isEmpty()) {
      throw new RendererTransportException('Consume pending renderer events before starting another session.');
    }
    $this->transport->start($session);
    $this->failure = null;
  }

  /** One zero-wait transport pass. A rejected batch never changes already queued events. */
  public function pump(): void
  {
    $this->receive();
  }

  private function receive(bool $discardKeys = false): void
  {
    if ($this->failure !== null) {
      throw $this->failure;
    }
    try {
      $batch = $this->transport->pollEvents();
      $keys = $this->keys->count();
      $events = $this->events->count();
      $bytes = $this->queuedBytes;
      foreach ($batch as $event) {
        if ($discardKeys && $event->type === RendererEventType::KEY) {
          continue;
        }
        $event->type === RendererEventType::KEY ? $keys++ : $events++;
        $bytes += self::eventBytes($event);
      }
      if ($keys > $this->maxPendingKeys || $events > $this->maxPendingEvents || $bytes > $this->maxQueuedBytes) {
        throw new RendererTransportException('Renderer client queue capacity exceeded; incoming batch rejected.',
          $this->transport->getDiagnostics(), $this->transport->getExitCode());
      }
      foreach ($batch as $event) {
        if ($event->type === RendererEventType::KEY) {
          if (! $discardKeys) {
            $this->keys->enqueue($event);
            LatencyTrace::keyStage($event, 'client.key.queued');
          }
        } else {
          $this->events->enqueue($event);
        }
      }
      $this->queuedBytes = $bytes;
      $this->traceQueue();
    } catch (RendererTransportException $error) {
      $this->failure = $error;
      throw $error;
    }
  }

  public function pollKey(): ?string
  {
    if ($this->keys->isEmpty()) {
      $this->pump();
    }
    if ($this->keys->isEmpty()) {
      return null;
    }
    $event = $this->keys->dequeue();
    $this->queuedBytes -= self::eventBytes($event);
    LatencyTrace::keyStage($event, 'client.key.dequeued', activate: true);
    $this->traceQueue();
    return $event->key;
  }

  /** @return list<RendererEvent> Non-input events only; queued keys are untouched. */
  public function pollEvents(): array
  {
    if ($this->events->isEmpty()) {
      $this->pump();
    }
    return $this->drainEvents();
  }

  /** @return list<RendererEvent> Consume already-pumped lifecycle events without another I/O pass. */
  public function drainEvents(): array
  {
    $events = [];
    while (! $this->events->isEmpty()) {
      $event = $this->events->dequeue();
      $this->queuedBytes -= self::eventBytes($event);
      $events[] = $event;
    }
    return $events;
  }

  /** Clear buffered gameplay keys, optionally including one bounded upstream pass. */
  public function resetKeys(bool $drainBufferedInput = false): void
  {
    $this->clearKeys();
    if ($drainBufferedInput) {
      try {
        $this->receive(discardKeys: true);
      } finally {
        $this->clearKeys();
      }
    }
  }

  private function clearKeys(): void
  {
    while (! $this->keys->isEmpty()) {
      $this->queuedBytes -= self::eventBytes($this->keys->dequeue());
    }
    $this->traceQueue();
  }

  private function traceQueue(): void
  {
    LatencyTrace::queue($this->keys->count(), $this->keys->isEmpty() ? null : $this->keys->bottom());
  }

  public function send(RendererMessage $message): void
  {
    if ($this->failure !== null) {
      throw $this->failure;
    }
    $this->transport->send($message);
  }

  public function isRunning(): bool
  {
    return $this->transport->isRunning();
  }

  public function shutdown(): ?int
  {
    $exitCode = $this->transport->shutdown();
    // Shutdown can drain final events into the transport. Keep them observable here.
    if ($this->failure === null) {
      $this->pump();
    }
    return $exitCode;
  }

  private static function eventBytes(RendererEvent $event): int
  {
    return strlen($event->key ?? '') + strlen($event->message ?? '');
  }
}
