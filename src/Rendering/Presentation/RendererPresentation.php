<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use InvalidArgumentException;
use OverflowException;

/** One presentation session; borrows the shared client without polling or closing it. */
final class RendererPresentation
{
  private int $frameNumber = 0;
  private ?RendererMessage $lastMessage = null;

  public function __construct(
    private readonly RendererClient $client,
    private readonly RendererGridConfig $grid,
  )
  {
  }

  /** @param list<PresentationSprite> $sprites Returns true only when a changed frame was queued. */
  public function present(ConsoleFrameSnapshot $snapshot, array $sprites = []): bool
  {
    if ($snapshot->width !== $this->grid->columns || $snapshot->height !== $this->grid->rows) {
      throw new InvalidArgumentException('Console snapshot dimensions must match the fixed renderer session grid.');
    }
    $number = $this->frameNumber < PHP_INT_MAX ? $this->frameNumber + 1 : $this->frameNumber;
    $message = new PresentationFrame($number, $snapshot->rows, $sprites)->toRendererMessage();
    if ($this->lastMessage !== null
      && $message->payload['text'] === $this->lastMessage->payload['text']
      && $message->payload['sprites'] === $this->lastMessage->payload['sprites']) {
      return false;
    }
    if ($this->frameNumber === PHP_INT_MAX) {
      throw new OverflowException('Presentation frame sequence exhausted the PHP integer range.');
    }
    // A failed enqueue must not suppress a retry or consume a sequence number.
    $this->client->send($message);
    $this->frameNumber = $number;
    $this->lastMessage = $message;
    return true;
  }
}
