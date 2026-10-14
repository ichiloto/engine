<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Immutable structured text with optional authoritative styled Terminal rows, independent of output ownership. */
final readonly class ConsolePresentationSnapshot
{
  /** @var list<PresentationTextLayer> */
  public array $textLayers;
  /** @var list<string>|null Styled rows supplied by Console capture, never recomposed from scalar runs. */
  public ?array $rows;

  /**
   * @param list<PresentationTextLayer> $textLayers
   * @param list<string>|null $rows Complete styled Terminal rows, or null for a layers-only snapshot.
   */
  public function __construct(public int $width, public int $height, array $textLayers, ?array $rows = null)
  {
    new RendererGridConfig($width, $height, 1, 1);
    $this->textLayers = new StyledPresentationFrame(0, $textLayers)->textLayers;
    foreach ($this->textLayers as $layer) {
      foreach ($layer->runs as $run) { $run->assertFits($width, $height); }
    }
    $copy = null;
    if ($rows !== null) {
      if (!array_is_list($rows) || count($rows) !== $height) {
        throw new InvalidArgumentException('Console presentation rows require exactly height styled rows.');
      }
      $copy = [];
      foreach ($rows as $row) {
        if (!is_string($row) || preg_match('//u', $row) !== 1) {
          throw new InvalidArgumentException('Console presentation rows must contain valid UTF-8 text.');
        }
        $copy[] = $row;
      }
    }
    $this->rows = $copy;
  }
}
