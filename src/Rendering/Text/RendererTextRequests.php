<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Text;

use Closure;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use LogicException;
use OverflowException;

/** The broker owns query lifetimes; input and retained generations are separate streams. @internal */
final class RendererTextRequests
{
  public const int MAX_REQUESTS = TextPreparationProviderInterface::MAX_REQUESTS;
  private readonly object $owner;
  private int $sequence = 0;
  /** @var array<string, RendererTextPreparationRequest> Prepared and cancelled in-flight ownership. */
  private array $requests = [];
  /** @var array<string, true> */
  private array $pendingReplies = [];
  /** @var array<string, true> */
  private array $releaseQueue = [];
  /** @var list<list<string>> */
  private array $releaseAcknowledgements = [];

  public function __construct(private readonly Closure $trySend) { $this->owner = new \stdClass(); }

  public function requestPreparation(TextSpec $spec): ?RendererTextPreparationRequest
  {
    $this->flushReleases();
    $cleanupCount = count($this->releaseQueue) + array_sum(array_map(count(...), $this->releaseAcknowledgements));
    if (count($this->requests) + $cleanupCount >= self::MAX_REQUESTS) {
      throw new OverflowException('Release unused prepared text; at most 64 requests may be owned, pending or awaiting cleanup.');
    }
    if ($this->sequence === PHP_INT_MAX) { throw new OverflowException('Text request sequence exhausted.'); }
    $id = 'text-query:' . ++$this->sequence;
    $message = new RendererMessage(RendererMessageType::PREPARE_TEXT,
      ['requestId' => $id, 'spec' => $spec->toArray()], RendererProtocolVersion::V2);
    if (!($this->trySend)($message)) { return null; }
    $request = new RendererTextPreparationRequest($id, $this->owner);
    $this->requests[$id] = $request;
    $this->pendingReplies[$id] = true;
    return $request;
  }

  public function releasePreparation(RendererTextPreparationRequest $request): void
  {
    $id = $request->requestId;
    if (($this->requests[$id] ?? null) !== $request) {
      throw new LogicException('Only this session\'s active text requests can be released.');
    }
    if ($request->state === RendererTextPreparationState::RELEASED) { return; }
    $measurement = $request->getResult();
    $request->retirePreparation($this->owner, released: true);
    if (!isset($this->pendingReplies[$id])) { unset($this->requests[$id]); }
    if ($measurement !== null) { $this->releaseQueue[$measurement->layoutId] = true; }
    $this->flushReleases();
  }

  /** Validate the entire incoming batch before the broker changes any queues or request results. */
  /** @param list<RendererEvent> $events */
  public function assertReplies(array $events): void
  {
    $pending = $this->pendingReplies;
    $acknowledgements = $this->releaseAcknowledgements;
    $measurements = [];
    foreach ($this->requests as $request) {
      if ($request->state === RendererTextPreparationState::PREPARED) {
        $result = $request->getResult();
        $measurements[$result->layoutId] = $result->metrics->toArray();
      }
    }
    foreach ($events as $event) {
      if ($event->type === RendererEventType::TEXT_RELEASED) {
        if (array_shift($acknowledgements) !== $event->layoutIds) {
          throw new RendererProtocolException('Text release reply does not match an outstanding release request.');
        }
      } elseif (self::isPreparationReply($event)) {
        if (!isset($pending[$event->requestId])) {
          throw new RendererProtocolException('Text preparation reply is unknown, duplicate or from a retired session.');
        }
        unset($pending[$event->requestId]);
        if ($event->type === RendererEventType::TEXT_PREPARED) {
          $metrics = $event->textMetrics->toArray();
          if (isset($measurements[$event->layoutId]) && $measurements[$event->layoutId] !== $metrics) {
            throw new RendererProtocolException('One immutable text layout cannot report different measurements.');
          }
          $measurements[$event->layoutId] = $metrics;
        }
      }
    }
  }

  /** Called only after the complete broker batch passed correlation and capacity admission. */
  /** @param list<RendererEvent> $events */
  public function acceptReplies(array $events): void
  {
    foreach ($events as $event) {
      if ($event->type === RendererEventType::TEXT_RELEASED) {
        array_shift($this->releaseAcknowledgements);
      } elseif (self::isPreparationReply($event)) {
        $request = $this->requests[$event->requestId];
        unset($this->pendingReplies[$event->requestId]);
        if ($request->state === RendererTextPreparationState::RELEASED) {
          unset($this->requests[$event->requestId]);
          if ($event->layoutId !== null) { $this->releaseQueue[$event->layoutId] = true; }
        } elseif ($event->type === RendererEventType::TEXT_PREPARED) {
          $request->completePreparation($this->owner, new TextMeasurement($event->layoutId, $event->textMetrics));
        } else {
          $request->completePreparation($this->owner, null, $event->message);
          unset($this->requests[$event->requestId]);
        }
      }
    }
  }

  /** Backpressure retains cleanup until a later zero-wait broker pass, never spins. */
  public function flushReleases(): void
  {
    // Pending results can share a cached handle; resolve their ownership before releasing it.
    if ($this->pendingReplies !== []) { return; }
    foreach ($this->requests as $request) {
      if ($request->state === RendererTextPreparationState::PREPARED) {
        unset($this->releaseQueue[$request->getResult()->layoutId]);
      }
    }
    if ($this->releaseQueue === [] || count($this->releaseAcknowledgements) >= self::MAX_REQUESTS) { return; }
    $ids = array_keys($this->releaseQueue);
    if (count($ids) > self::MAX_REQUESTS) { throw new LogicException('Prepared-text release accounting exceeded its bound.'); }
    $message = new RendererMessage(RendererMessageType::RELEASE_TEXT, ['layoutIds' => $ids], RendererProtocolVersion::V2);
    if (($this->trySend)($message)) {
      $this->releaseAcknowledgements[] = $ids;
      $this->releaseQueue = [];
    }
  }

  public function retireSession(): void
  {
    foreach ($this->requests as $request) { $request->retirePreparation($this->owner); }
    $this->requests = $this->pendingReplies = $this->releaseQueue = $this->releaseAcknowledgements = [];
    // Sequence is deliberately not reset: old replies cannot collide with a new session's IDs.
  }

  public static function isTextReply(RendererEvent $event): bool
  {
    return self::isPreparationReply($event) || $event->type === RendererEventType::TEXT_RELEASED;
  }

  private static function isPreparationReply(RendererEvent $event): bool
  {
    return $event->type === RendererEventType::TEXT_PREPARED || $event->type === RendererEventType::TEXT_PREPARE_FAILED;
  }
}
