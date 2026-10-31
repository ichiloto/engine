<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use LogicException;

/** Transient presentation ownership; callers save their own completion only after delivery. */
final class PresentationDeliveryReceipt
{
  public private(set) PresentationDeliveryState $state = PresentationDeliveryState::QUEUED;
  public bool $isComplete { get => $this->state === PresentationDeliveryState::COMPLETED; }
  public bool $isTerminal { get => $this->isComplete || $this->state === PresentationDeliveryState::CANCELLED; }

  public function beginDelivery(): bool
  {
    if ($this->isTerminal) { return false; }
    $this->state = PresentationDeliveryState::PRESENTING;
    return true;
  }

  public function completeDelivery(): void
  {
    if ($this->isTerminal) { return; }
    if ($this->state !== PresentationDeliveryState::PRESENTING) {
      throw new LogicException('Presentation must be delivered before it can complete.');
    }
    $this->state = PresentationDeliveryState::COMPLETED;
  }

  public function retryDelivery(): void
  {
    if (!$this->isTerminal) { $this->state = PresentationDeliveryState::QUEUED; }
  }

  public function cancel(): void
  {
    if (!$this->isComplete) { $this->state = PresentationDeliveryState::CANCELLED; }
  }
}
