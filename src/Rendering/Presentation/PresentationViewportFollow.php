<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/**
 * The field sprite the camera follows. When the camera origin changes in the
 * same frame that sprite slides a step, the renderer moves the camera with
 * it, so the followed character stays steady while the field scrolls. Text
 * layers drawn relative to that character (its action prompt) move with it
 * rather than with the field.
 */
final readonly class PresentationViewportFollow
{
  /** @param list<string> $textLayerIds */
  public function __construct(public string $spriteId, public array $textLayerIds = [])
  {
    if ($spriteId === '' || strlen($spriteId) > 256 || preg_match('//u', $spriteId) !== 1
      || !array_is_list($textLayerIds) || count(array_unique($textLayerIds)) !== count($textLayerIds)) {
      throw new InvalidArgumentException('A camera follow names one sprite and unique anchored text layer IDs.');
    }
  }

  /** @return array{spriteId: string, textLayerIds: list<string>} */
  public function toArray(): array
  {
    return ['spriteId' => $this->spriteId, 'textLayerIds' => $this->textLayerIds];
  }
}
