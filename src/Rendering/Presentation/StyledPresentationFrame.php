<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use InvalidArgumentException;

final readonly class StyledPresentationFrame
{
  /** @var list<PresentationTextLayer> */
  public array $textLayers;
  /** @var list<PresentationSprite> */
  public array $sprites;

  /** @param list<PresentationTextLayer> $textLayers @param list<PresentationSprite> $sprites */
  public function __construct(public int $number, array $textLayers = [], array $sprites = [])
  {
    if ($number < 0 || !array_is_list($textLayers) || count($textLayers) > 64) {
      throw new InvalidArgumentException('Styled frame requires a nonnegative number and at most 64 text layers.');
    }
    $ids = $copy = [];
    $runs = $scalars = 0;
    foreach ($textLayers as $layer) {
      if (!$layer instanceof PresentationTextLayer || isset($ids[$layer->id])) {
        throw new InvalidArgumentException('Styled frame requires typed text layers with unique IDs.');
      }
      $ids[$layer->id] = true;
      $runs += count($layer->runs);
      foreach ($layer->runs as $run) { $scalars += mb_strlen($run->text, 'UTF-8'); }
      $copy[] = $layer;
    }
    if ($runs > 32768 || $scalars > 524288) {
      throw new InvalidArgumentException('Styled frame exceeds renderer run/scalar limits.');
    }
    // Stable sorting preserves the frame array order for equal numeric layers.
    usort($copy, static fn(PresentationTextLayer $a, PresentationTextLayer $b) => $a->layer <=> $b->layer);
    $this->textLayers = $copy;
    $this->sprites = PresentationSprite::orderedList($sprites);
  }

  public function toRendererMessage(): RendererMessage
  {
    return new RendererMessage(RendererMessageType::FRAME, [
      'frame' => $this->number,
      'textLayers' => array_map(static fn(PresentationTextLayer $layer) => $layer->toArray(), $this->textLayers),
      'sprites' => array_map(static fn(PresentationSprite $sprite) => $sprite->toArray(), $this->sprites),
    ], RendererProtocolVersion::V2);
  }
}
