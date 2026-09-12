<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** Immutable scalar-aligned structured text, independent of terminal output ownership. */
final readonly class ConsolePresentationSnapshot
{
  /** @var list<PresentationTextLayer> */
  public array $textLayers;

  /** @param list<PresentationTextLayer> $textLayers */
  public function __construct(public int $width, public int $height, array $textLayers)
  {
    new RendererGridConfig($width, $height, 1, 1);
    $this->textLayers = new StyledPresentationFrame(0, $textLayers)->textLayers;
    foreach ($this->textLayers as $layer) {
      foreach ($layer->runs as $run) { $run->assertFits($width, $height); }
    }
  }
}
