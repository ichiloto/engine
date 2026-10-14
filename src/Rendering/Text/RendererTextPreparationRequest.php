<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Text;

use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use LogicException;

/** One session-owned, nonblocking request. Never store it in project data or saves. */
final class RendererTextPreparationRequest
{
  public private(set) RendererTextPreparationState $state = RendererTextPreparationState::PENDING;
  private ?TextMeasurement $measurement = null;
  private ?string $failure = null;

  /** @internal RendererTextRequests alone owns completion and retirement. */
  public function __construct(public readonly string $requestId, private readonly object $owner) {}

  /** Null means pending; a refusal remains observable after the connection accepts other work. */
  public function getResult(): ?TextMeasurement
  {
    return match ($this->state) {
      RendererTextPreparationState::PENDING => null,
      RendererTextPreparationState::PREPARED => $this->measurement,
      RendererTextPreparationState::FAILED => throw new RendererTextPreparationException(
        $this->failure ?? throw new LogicException('A failed text request must retain its diagnostic.')),
      default => throw new LogicException('The prepared text request was released or its renderer session retired.'),
    };
  }

  /** @internal */
  public function completePreparation(object $owner, ?TextMeasurement $measurement, ?string $failure = null): void
  {
    $this->assertOwner($owner);
    if ($this->state !== RendererTextPreparationState::PENDING) {
      throw new LogicException('Only pending text requests may complete.');
    }
    if (($measurement === null) === ($failure === null)) {
      throw new LogicException('Text preparation requires exactly one result or diagnostic.');
    }
    $this->measurement = $measurement;
    $this->failure = $failure;
    $this->state = $measurement === null ? RendererTextPreparationState::FAILED : RendererTextPreparationState::PREPARED;
  }

  /** @internal */
  public function retirePreparation(object $owner, bool $released = false): void
  {
    $this->assertOwner($owner);
    $this->measurement = null;
    $this->state = $released ? RendererTextPreparationState::RELEASED : RendererTextPreparationState::RETIRED;
  }

  private function assertOwner(object $owner): void
  {
    if ($owner !== $this->owner) { throw new LogicException('A text request belongs to its renderer session.'); }
  }
}
