<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Only this local text grid is cell-based. Null canvas backgrounds are transparent. */
final readonly class CanvasTextLayer
{
  /** @var list<PresentationTextRun> */
  public array $runs;
  public CanvasRectangle $bounds;
  public CanvasRectangle $paintBounds;

  /** @param list<PresentationTextRun> $runs */
  public function __construct(
    public string $id,
    public int $layer,
    public float $x,
    public float $y,
    public RendererGridConfig $grid,
    array $runs,
    public ?CanvasRectangle $clipRect = null,
    public float $opacity = 1,
    public ?CanvasGlyphEffects $glyphEffects = null,
  )
  {
    CanvasValidation::id($id);
    if (!is_finite($opacity) || $opacity < 0 || $opacity > 1) {
      throw new InvalidArgumentException('Canvas text opacity must be finite and in 0..1.');
    }
    $this->runs = new PresentationTextLayer($id, $layer, $runs)->runs;
    $this->bounds = new CanvasRectangle($x, $y, $grid->columns * $grid->cellWidth, $grid->rows * $grid->cellHeight);
    $padding = $glyphEffects?->padding() ?? ['left' => 0, 'top' => 0, 'right' => 0, 'bottom' => 0];
    $this->paintBounds = new CanvasRectangle($x - $padding['left'], $y - $padding['top'],
      $this->bounds->width + $padding['left'] + $padding['right'],
      $this->bounds->height + $padding['top'] + $padding['bottom']);
    foreach ($this->runs as $run) { $run->assertFits($grid->columns, $grid->rows); }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'origin' => ['x' => $this->x, 'y' => $this->y],
      'grid' => $this->grid->toArray(),
      'runs' => array_map(static fn(PresentationTextRun $run) => $run->toArray(), $this->runs),
      'layer' => $this->layer,
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()]),
      ...($this->opacity === 1.0 ? [] : ['opacity' => $this->opacity]),
      ...($this->glyphEffects === null ? [] : ['glyphEffects' => $this->glyphEffects->toArray()])];
  }
}
