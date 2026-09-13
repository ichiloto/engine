<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use InvalidArgumentException;
use OverflowException;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;

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

  /**
   * @param list<PresentationSprite> $sprites
   * @param list<PresentationTileBatch> $tileBatches
   * Returns true only when a changed frame was queued.
   */
  public function present(ConsoleFrameSnapshot|ConsolePresentationSnapshot $snapshot, array $sprites = [], array $tileBatches = []): bool
  {
    if ($snapshot->width !== $this->grid->columns || $snapshot->height !== $this->grid->rows) {
      throw new InvalidArgumentException('Console snapshot dimensions must match the fixed renderer session grid.');
    }
    foreach ($sprites as $sprite) {
      if ($sprite->sourceRect !== null && !$this->client->supports(RendererSessionConfig::SPRITE_SOURCE_RECT)) {
        throw new RendererProtocolException('Sprite sheets require negotiated sprite_source_rect support. Request it at startup and install an updated renderer.');
      }
    }
    if ($tileBatches !== [] && (!$snapshot instanceof ConsolePresentationSnapshot
      || !$this->client->supports(RendererSessionConfig::TILE_BATCHES))) {
      throw new RendererProtocolException('Graphical terrain requires protocol v2 and negotiated tile_batches support. Request it at startup and install an updated renderer.');
    }
    foreach ($tileBatches as $batch) { $batch->assertWithin($this->grid); }
    $preparation = LatencyTrace::now();
    $number = $this->frameNumber < PHP_INT_MAX ? $this->frameNumber + 1 : $this->frameNumber;
    $message = $snapshot instanceof ConsolePresentationSnapshot
      ? new StyledPresentationFrame($number, $snapshot->textLayers, $sprites, $tileBatches)->toRendererMessage()
      : new PresentationFrame($number, $snapshot->rows, $sprites)->toRendererMessage();
    LatencyTrace::end('presentation.message', $preparation);
    $comparison = LatencyTrace::now();
    $content = $message->payload;
    $previous = $this->lastMessage?->payload ?? [];
    unset($content['frame'], $previous['frame']);
    $unchanged = $this->lastMessage !== null
      && $message->protocol === $this->lastMessage->protocol && $content === $previous;
    LatencyTrace::end('presentation.compare', $comparison, ['unchanged' => $unchanged]);
    if (LatencyTrace::enabled()) {
      LatencyTrace::record('presentation.content', ['sha256' => hash('sha256', serialize($content))]);
    }
    if ($unchanged) {
      return false;
    }
    if ($this->frameNumber === PHP_INT_MAX) {
      throw new OverflowException('Presentation frame sequence exhausted the PHP integer range.');
    }
    // A failed enqueue must not suppress a retry or consume a sequence number.
    $this->client->send($message);
    $this->frameNumber = $number;
    $this->lastMessage = $message;
    LatencyTrace::record('presentation.frame.queued', ['frame' => $number]);
    return true;
  }
}
