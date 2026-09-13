<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use InvalidArgumentException;

/** An atomic full replacement. Frame numbers are labels, never simulation clocks. */
final readonly class PresentationFrame
{
  /** @var list<string> */
  public array $text;
  /** @var list<PresentationSprite> Ascending layers, with stable ordering for ties. */
  public array $sprites;

  /**
   * @param list<string> $text
   * @param list<PresentationSprite> $sprites
   */
  public function __construct(
    public int $number,
    array $text = [],
    array $sprites = [],
  )
  {
    if ($number < 0) {
      throw new InvalidArgumentException('Presentation frame number must be non-negative.');
    }
    if (! array_is_list($text) || count($text) > 256) {
      throw new InvalidArgumentException('Presentation text must be a list of at most 256 rows.');
    }
    $textCopy = [];
    foreach ($text as $row) {
      if (! is_string($row) || preg_match('//u', $row) !== 1 || preg_match('/\p{Cc}/u', $row) === 1
        || mb_strlen($row, 'UTF-8') > 512) {
        throw new InvalidArgumentException('Presentation rows require UTF-8 without controls and at most 512 scalar cells.');
      }
      $textCopy[] = $row;
    }
    $this->text = $textCopy;
    $this->sprites = PresentationSprite::orderedList($sprites);
  }

  public function toRendererMessage(): RendererMessage
  {
    return new RendererMessage(RendererMessageType::FRAME, [
      'frame' => $this->number,
      'text' => $this->text,
      'sprites' => array_map(static fn(PresentationSprite $sprite): array => $sprite->toArray(), $this->sprites),
    ]);
  }
}
