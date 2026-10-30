<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use InvalidArgumentException;

/** A field lane keeps control until each themed notice has been acknowledged. */
final class NoticeSequenceOperation implements EventPendingOperationInterface
{
  private readonly object $owner;
  private int $next = 0;
  private bool $cancelled = false;

  /** @param list<NoticeContent> $notices */
  public function __construct(private readonly ModalManager $modals, private readonly array $notices)
  {
    if (!array_is_list($notices) || !array_all($notices, static fn(mixed $notice): bool => $notice instanceof NoticeContent)) {
      throw new InvalidArgumentException('A notice sequence requires a list of NoticeContent values.');
    }
    $this->owner = new \stdClass();
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->cancelled) { return true; }
    if ($this->modals->hasPendingNotices($this->owner)) { return false; }
    if ($this->next === count($this->notices)) { return true; }
    $this->modals->queueNotice($this->notices[$this->next], $this->owner);
    $this->next++;
    return false;
  }

  public function cancel(): void
  {
    $this->cancelled = true;
    $this->modals->cancelNotices($this->owner);
  }
}
