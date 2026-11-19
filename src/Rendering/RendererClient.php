<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\KeyHoldState;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Interfaces\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationRequest;
use Ichiloto\Engine\Rendering\Text\RendererTextRequests;
use InvalidArgumentException;
use LogicException;
use SplQueue;
use Ichiloto\Engine\Rendering\Assets\AssetHoldLeases;

/** The sole event consumer for a shared renderer connection. No input/action mapping. */
final class RendererClient implements \Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface
{
  /** @var SplQueue<array{event: RendererEvent, held: ?list<string>, reset: int, bytes: int}> */
  private SplQueue $keys;
  /** Discrete eligibility belongs to the dequeued key and expires on an input reset. */
  private ?bool $lastPollPress = null;
  /** @var ?list<string> Held keys when the last fresh press was received, or null when unavailable. */
  private ?array $lastPressHeldKeys = null;
  private int $inputResetGeneration = 0;
  /** @var SplQueue<RendererEvent> */
  private SplQueue $events;
  /** @var SplQueue<RendererEvent> Negotiated presses, releases and resets, in arrival order; repeats stay keys only. */
  private SplQueue $transitions;
  /** @var array<string, string> Physical identity and its press-time key survive queue drains. */
  private array $heldControls = [];
  /** @var array<string, true> Context-reset controls cannot repeat into their next owner. */
  private array $quarantinedControls = [];
  /** @var array<int, RendererEvent> Latest pending generation per staged/presented state; at most two. */
  private array $frameAcknowledgements = [];
  private int $queuedBytes = 0;
  private ?RendererTransportException $failure = null;
  /** @var list<string> */
  private array $requiredCapabilities = [];
  /** @var list<string> Drawing features plus explicitly subscribed event extensions. */
  private array $negotiableCapabilities = [];
  /** @var list<string> */
  private array $capabilities = [];
  private readonly RendererTextRequests $textRequests;
  private bool $textSessionActive = false;
  private ?object $textSession = null;
  public readonly AssetHoldLeases $assetHolds;

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
    $this->transitions = new SplQueue();
    $this->textRequests = new RendererTextRequests($this->trySend(...));
    $this->assetHolds = new AssetHoldLeases();
  }

  public function start(RendererSessionConfig $session): void
  {
    if (! $this->keys->isEmpty() || ! $this->events->isEmpty() || ! $this->transitions->isEmpty()
      || $this->frameAcknowledgements !== []) {
      throw new RendererTransportException('Consume pending renderer events before starting another session.');
    }
    $this->capabilities = [];
    $this->lastPollPress = $this->lastPressHeldKeys = null;
    $this->inputResetGeneration = 0;
    $this->heldControls = $this->quarantinedControls = [];
    $this->textRequests->retireSession();
    $this->assetHolds->endSession();
    $this->textSessionActive = false;
    $this->textSession = null;
    $this->requiredCapabilities = $session->requiredCapabilities;
    $this->negotiableCapabilities = $session->getNegotiableCapabilities();
    $this->transport->start($session);
    $this->textSessionActive = true;
    $this->textSession = new \stdClass();
    $this->failure = null;
  }

  /** One zero-wait transport pass. A rejected batch never changes already queued events. */
  public function pump(): void
  {
    $this->receive();
  }

  /** Use a bounded pipe wait during the game loop's existing idle window. */
  public function waitForTraffic(float $seconds): void
  {
    $this->receive(waitSeconds: $seconds);
  }

  private function receive(bool $discardKeys = false, float $waitSeconds = 0.0): void
  {
    if ($this->failure !== null) {
      throw $this->failure;
    }
    try {
      $batch = $this->transport->pollEvents($waitSeconds);
      $keys = $this->keys->count();
      $transitions = $this->transitions->count();
      $events = $this->events->count();
      $bytes = $this->queuedBytes;
      $textReplies = $textReplyBytes = 0;
      $assetReplies = $assetReplyBytes = 0;
      $assetFeedbacks = [];
      $assetSessionSupport = null;
      $capabilities = $this->capabilities;
      $acknowledgements = $this->frameAcknowledgements;
      $heldControls = $this->heldControls;
      $quarantinedControls = $this->quarantinedControls;
      $suppressedKeys = [];
      $admittedKeys = [];
      $keyBytes = 0;
      $inputResetGeneration = $this->inputResetGeneration;
      $this->textRequests->assertReplies($batch);
      foreach ($batch as $index => $event) {
        if ($event->type === RendererEventType::READY) {
          $event->requireCapabilities($this->requiredCapabilities);
          $capabilities = array_values(array_intersect($this->negotiableCapabilities, $event->capabilities));
          $assetSessionSupport = in_array(RendererSessionConfig::ASSET_HOLDS, $capabilities, true);
        }
        if ($event->assetFeedback !== null) {
          if (!in_array(RendererSessionConfig::ASSET_HOLDS, $capabilities, true)) {
            throw new RendererProtocolException('Asset hold replies require negotiated asset_holds support.');
          }
          $assetFeedbacks[] = $event->assetFeedback;
          $assetReplies++;
          $assetReplyBytes += $event->assetFeedbackBytes;
        }
        if ($event->type === RendererEventType::ASSET_HOLDS) { continue; }
        if ($event->type === RendererEventType::WINDOW_ACTIVATION
          && !in_array(RendererSessionConfig::WINDOW_ACTIVATION, $capabilities, true)) {
          throw new RendererProtocolException('Window activation requires negotiated window_activation support.');
        }
        self::assertKeyTransitionContract($event, in_array(RendererSessionConfig::KEY_TRANSITIONS, $capabilities, true),
          in_array(RendererSessionConfig::MODIFIER_CONTROLS, $capabilities, true));
        if ($event->type === RendererEventType::INPUT_RESET) {
          ++$inputResetGeneration;
          $heldControls = $quarantinedControls = [];
        } elseif ($event->type === RendererEventType::KEY_RELEASE) {
          unset($heldControls[$event->control], $quarantinedControls[$event->control]);
        } elseif ($event->type === RendererEventType::KEY && $event->control !== null) {
          if ($event->repeat === false) {
            $heldControls[$event->control] ??= $event->key;
            if (count($heldControls) > KeyHoldState::MAX_HELD_CONTROLS) {
              throw new RendererProtocolException('Renderer input exceeds the supported held-control limit.');
            }
            if ($discardKeys) { $quarantinedControls[$event->control] = true; }
          }
          if (isset($quarantinedControls[$event->control])) { $suppressedKeys[$index] = true; }
        }
        if (RendererTextRequests::isTextReply($event)) {
          if (!in_array(RendererSessionConfig::TEXT_LAYOUT, $capabilities, true)) {
            throw new RendererProtocolException('Prepared text replies require negotiated text_layout support.');
          }
          $textReplies++;
          $textReplyBytes += self::eventBytes($event);
          continue;
        }
        if (isset($suppressedKeys[$index])
          || ($discardKeys && ($event->type === RendererEventType::KEY || self::isKeyTransition($event)))) {
          continue;
        }
        if (self::isKeyTransition($event)) {
          $transitions++;
          $bytes += self::eventBytes($event);
          if ($event->type !== RendererEventType::KEY) {
            continue;
          }
        }
        if ($event->type === RendererEventType::FRAME_ACK) {
          $progress = $event->createProgressAcknowledgement();
          $slot = (int) $event->presented;
          $previous = $acknowledgements[$slot] ?? null;
          if ($previous === null || $event->generation >= $previous->generation) {
            $bytes += self::eventBytes($progress) - ($previous === null ? 0 : self::eventBytes($previous));
            $acknowledgements[$slot] = $progress;
          }
          continue;
        }
        $eventBytes = self::eventBytes($event);
        if ($event->type === RendererEventType::KEY) {
          $keys++;
          $held = $event->repeat === false ? array_values(array_diff_key($heldControls, $quarantinedControls)) : null;
          foreach ($held ?? [] as $key) {
            $eventBytes += strlen($key) + 8;
          }
          // Reject oversized key batches before retaining any more held-key snapshots.
          $keyBytes += $eventBytes;
          if ($keys > $this->maxPendingKeys || $keyBytes > $this->maxQueuedBytes) {
            throw new RendererTransportException('Renderer client queue capacity exceeded; incoming batch rejected.',
              $this->transport->getDiagnostics(), $this->transport->getExitCode());
          }
          $admittedKeys[$index] = ['event' => $event, 'held' => $held, 'reset' => $inputResetGeneration, 'bytes' => $eventBytes];
        } else {
          $events++;
        }
        $bytes += $eventBytes;
      }
      if ($assetFeedbacks !== []) {
        try { $this->assetHolds->assertFeedbacks($assetFeedbacks, $assetSessionSupport); }
        catch (LogicException $error) { throw new RendererProtocolException('Invalid asset hold transition: ' . $error->getMessage(), previous: $error); }
      }
      if ($keys > $this->maxPendingKeys || $transitions > $this->maxPendingKeys || $events + $textReplies + $assetReplies > $this->maxPendingEvents
        || $bytes + $textReplyBytes + $assetReplyBytes > $this->maxQueuedBytes) {
        throw new RendererTransportException('Renderer client queue capacity exceeded; incoming batch rejected.',
          $this->transport->getDiagnostics(), $this->transport->getExitCode());
      }
      foreach ($batch as $index => $event) {
        if (isset($suppressedKeys[$index])) { continue; }
        if (RendererTextRequests::isTextReply($event)) { continue; }
        if ($event->type === RendererEventType::ASSET_HOLDS) { continue; }
        if ($event->type === RendererEventType::FRAME_ACK) {
          continue;
        }
        if (self::isKeyTransition($event) && ! $discardKeys) {
          $this->transitions->enqueue($event);
        }
        if ($event->type === RendererEventType::KEY_RELEASE || $event->type === RendererEventType::INPUT_RESET) {
          continue;
        }
        if ($event->type === RendererEventType::KEY) {
          if (! $discardKeys) {
            $this->keys->enqueue($admittedKeys[$index]);
            LatencyTrace::keyStage($event, 'client.key.queued');
          }
        } else {
          if ($event->type === RendererEventType::READY) {
            $this->capabilities = array_values(array_intersect($this->negotiableCapabilities, $event->capabilities));
          }
          $this->events->enqueue($event);
        }
      }
      $this->queuedBytes = $bytes;
      if ($this->inputResetGeneration !== $inputResetGeneration && $this->lastPollPress === true) {
        $this->lastPollPress = false;
        $this->lastPressHeldKeys = null;
      }
      $this->inputResetGeneration = $inputResetGeneration;
      $this->heldControls = $heldControls;
      $this->quarantinedControls = $quarantinedControls;
      $this->frameAcknowledgements = $acknowledgements;
      if ($assetSessionSupport !== null) { $this->assetHolds->startSession($assetSessionSupport); }
      foreach ($assetFeedbacks as $feedback) { $this->assetHolds->applyFeedback($feedback); }
      $this->textRequests->acceptReplies($batch);
      if (!$this->isRunning() || array_any($batch, static fn(RendererEvent $event) => $event->type === RendererEventType::CLOSE_REQUESTED)) {
        $this->textSessionActive = false;
        $this->textRequests->retireSession();
        $this->assetHolds->endSession();
      } else { $this->textRequests->flushReleases(); }
      $this->traceQueue();
    } catch (RendererTransportException $error) {
      $this->failure = $error;
      $this->lastPressHeldKeys = null;
      $this->heldControls = $this->quarantinedControls = [];
      $this->textRequests->retireSession();
      $this->assetHolds->endSession();
      $this->textSessionActive = false;
      throw $error;
    }
  }

  public function pollKey(): ?string
  {
    $this->lastPollPress = $this->lastPressHeldKeys = null;
    if ($this->keys->isEmpty()) {
      $this->pump();
    }
    if ($this->keys->isEmpty()) {
      return null;
    }
    $sample = $this->keys->dequeue();
    $event = $sample['event'];
    $this->lastPollPress = match (true) {
      $sample['reset'] !== $this->inputResetGeneration => false,
      $event->repeat === null => null,
      default => !$event->repeat,
    };
    $this->lastPressHeldKeys = $this->lastPollPress === true ? $sample['held'] : null;
    $this->queuedBytes -= $sample['bytes'];
    LatencyTrace::keyStage($event, 'client.key.dequeued', activate: true);
    $this->traceQueue();
    return $event->key;
  }

  /** Fresh/repeated status of the last dequeued key, or null for legacy samples. */
  public function wasLastPollPress(): ?bool
  {
    return $this->lastPollPress;
  }

  /** True if the key was held during the last dequeued fresh press. */
  public function wasKeyHeldAtLastPress(KeyCode $key): ?bool
  {
    if ($this->lastPollPress !== true) { return $this->lastPollPress; }
    return in_array($key->value, $this->lastPressHeldKeys ?? [], true);
  }

  /** @return list<RendererEvent> Non-input events, including coalesced ACK progress; keys are untouched. */
  public function pollEvents(): array
  {
    if ($this->events->isEmpty() && $this->frameAcknowledgements === []) {
      $this->pump();
    }
    return $this->drainEvents();
  }

  /** @return list<RendererEvent> Ordered lifecycle events followed by coalesced ACK progress; no I/O. */
  public function drainEvents(): array
  {
    $events = [];
    while (! $this->events->isEmpty()) {
      $event = $this->events->dequeue();
      $this->queuedBytes -= self::eventBytes($event);
      $events[] = $event;
    }
    return [...$events, ...$this->drainFrameAcknowledgements()];
  }

  /** @return list<RendererEvent> At most two latest ACKs in generation order; input/lifecycle queues untouched. */
  public function drainFrameAcknowledgements(): array
  {
    $acknowledgements = array_values($this->frameAcknowledgements);
    $this->frameAcknowledgements = [];
    foreach ($acknowledgements as $event) {
      $this->queuedBytes -= self::eventBytes($event);
    }
    usort($acknowledgements, static fn(RendererEvent $a, RendererEvent $b) => $a->generation <=> $b->generation);
    return $acknowledgements;
  }

  /**
   * Negotiated key presses, releases and resets received so far, in arrival order. No I/O:
   * the caller pumps once per update and processes this bounded batch before gameplay.
   *
   * @return list<RendererEvent>
   */
  public function drainKeyTransitions(): array
  {
    $transitions = [];
    while (! $this->transitions->isEmpty()) {
      $event = $this->transitions->dequeue();
      $this->queuedBytes -= self::eventBytes($event);
      $transitions[] = $event;
    }
    return $transitions;
  }

  /** Clear buffered gameplay keys, optionally including one bounded upstream pass. */
  public function resetKeys(bool $drainBufferedInput = false): void
  {
    $this->quarantinedControls = $this->heldControls;
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
    $this->lastPollPress = $this->lastPressHeldKeys = null;
    while (! $this->keys->isEmpty()) {
      $this->queuedBytes -= $this->keys->dequeue()['bytes'];
    }
    $this->drainKeyTransitions();
    $this->traceQueue();
  }

  private function traceQueue(): void
  {
    LatencyTrace::queue($this->keys->count(), $this->keys->isEmpty() ? null : $this->keys->bottom()['event']);
  }

  public function send(RendererMessage $message): void
  {
    if ($this->failure !== null) {
      throw $this->failure;
    }
    $this->transport->send($message);
  }

  public function trySend(RendererMessage $message): bool
  {
    if ($this->failure !== null) { throw $this->failure; }
    return $this->transport->trySend($message);
  }

  public function getPendingWriteBytes(): int { return $this->transport->getPendingWriteBytes(); }

  public function isRunning(): bool
  {
    return $this->transport->isRunning();
  }

  public function supports(string $capability): bool
  {
    return in_array($capability, $this->capabilities, true);
  }

  public function getTextPreparationSession(): ?object
  {
    return $this->textSessionActive ? $this->textSession : null;
  }

  /** Null is transport backpressure. Pump once per update; inspect the returned request without I/O. */
  public function requestTextPreparation(TextSpec $spec): ?RendererTextPreparationRequest
  {
    if (!$this->textSessionActive || !$this->isRunning() || !$this->supports(RendererSessionConfig::TEXT_LAYOUT)) {
      throw new LogicException('Project text preparation requires an active renderer with text_layout support.');
    }
    if (($spec->wrapWidth !== null || $spec->lineHeight !== null) && !$this->supports(RendererSessionConfig::TEXT_BLOCK_LAYOUT)) {
      throw new LogicException('Wrapped or custom-height text requires negotiated text_block_layout support.');
    }
    try { return $this->textRequests->requestPreparation($spec); }
    catch (RendererTransportException $error) {
      $this->failure = $error;
      $this->textRequests->retireSession();
      $this->assetHolds->endSession();
      $this->textSessionActive = false;
      throw $error;
    }
  }

  public function releaseTextPreparation(RendererTextPreparationRequest $request): void
  {
    try { $this->textRequests->releasePreparation($request); }
    catch (RendererTransportException $error) {
      $this->failure = $error;
      $this->textRequests->retireSession();
      $this->assetHolds->endSession();
      $this->textSessionActive = false;
      throw $error;
    }
  }

  public function shutdown(): ?int
  {
    try {
      $exitCode = $this->transport->shutdown();
      // Shutdown can drain final events into the transport. Keep them observable here.
      if ($this->failure === null) { $this->pump(); }
      return $exitCode;
    } finally {
      $this->lastPollPress = $this->lastPressHeldKeys = null;
      $this->heldControls = $this->quarantinedControls = [];
      $this->textRequests->retireSession();
      $this->assetHolds->endSession();
      $this->textSessionActive = false;
    }
  }

  /** A press (a key that repeats nothing), a release or a reset; never an OS repeat. */
  private static function isKeyTransition(RendererEvent $event): bool
  {
    return ($event->type === RendererEventType::KEY && $event->repeat === false)
      || $event->type === RendererEventType::KEY_RELEASE || $event->type === RendererEventType::INPUT_RESET;
  }

  /**
   * Transitions are an explicit subscription; a subscribed session never receives an unidentified key. Shift
   * alone is a control only in a session that also subscribed to modifier controls.
   */
  private static function assertKeyTransitionContract(RendererEvent $event, bool $negotiated, bool $modifierControls): void
  {
    if (! $modifierControls && ($event->key === KeyCode::SHIFT->value || $event->control === KeyCode::SHIFT->value)) {
      throw new RendererProtocolException('Shift as a control requires negotiated modifier_controls support.');
    }
    $transition = $event->type === RendererEventType::KEY_RELEASE || $event->type === RendererEventType::INPUT_RESET
      || $event->control !== null;
    if ($transition && ! $negotiated) {
      throw new RendererProtocolException('Key transitions require negotiated key_transitions support.');
    }
    if ($negotiated && $event->type === RendererEventType::KEY && $event->control === null) {
      throw new RendererProtocolException('A key_transitions session requires a control identity on every key.');
    }
  }

  private static function eventBytes(RendererEvent $event): int
  {
    return strlen($event->key ?? '') + strlen($event->control ?? '') + strlen($event->message ?? '')
      + array_sum(array_map(strlen(...), $event->capabilities)) + ($event->active === null ? 0 : 1)
      + ($event->generation === null ? 0 : 8) + ($event->frame === null ? 0 : 8)
      + ($event->expectedGeneration === null ? 0 : 8) + ($event->presented === null ? 0 : 1)
      + ($event->resyncRequired === null ? 0 : 1) + ($event->repeat === null ? 0 : 1)
      + strlen($event->requestId ?? '') + strlen($event->layoutId ?? '')
      + array_sum(array_map(strlen(...), $event->layoutIds))
      + ($event->textMetrics === null ? 0 : strlen(json_encode($event->textMetrics->toArray(), JSON_THROW_ON_ERROR)))
      + $event->assetFeedbackBytes;
  }
}
