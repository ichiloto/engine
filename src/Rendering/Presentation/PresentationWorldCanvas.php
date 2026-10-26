<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** An atomic graphical subject anchored in field cells; child geometry remains local. */
final readonly class PresentationWorldCanvas
{
  public function __construct(
    public string $id,
    public int $x,
    public int $y,
    public PresentationSpritePivot $pivot,
    public PresentationCanvas $canvas,
    public int $layer = 0,
  ) {
    CanvasValidation::id($id);
    if ($canvas->viewportFit !== CanvasViewportFit::NATIVE) {
      throw new InvalidArgumentException('World canvases inherit their field viewport and cannot request window fitting.');
    }
    foreach ([$x, $y, $layer] as $value) { SpriteValidation::validateSigned32BitRange($value); }
  }

  /** Project world coordinates into the selected runtime camera without altering local drawing. */
  public function createProjected(int $originX, int $originY): self
  {
    SpriteValidation::validateSigned32BitRange($originX);
    SpriteValidation::validateSigned32BitRange($originY);
    return new self($this->id, $this->x - $originX, $this->y - $originY, $this->pivot, $this->canvas, $this->layer);
  }

  /** @param list<self> $canvases @return list<self> */
  public static function getOrderedList(array $canvases): array
  {
    if (!array_is_list($canvases) || count($canvases) > 1024) {
      throw new InvalidArgumentException('World canvases require a list of at most 1024 entries.');
    }
    $ids = $copy = [];
    foreach ($canvases as $canvas) {
      if (!$canvas instanceof self || isset($ids[$canvas->id])) {
        throw new InvalidArgumentException('World canvases require typed entries with unique IDs.');
      }
      $ids[$canvas->id] = true;
      $copy[] = $canvas;
    }
    usort($copy, static fn(self $a, self $b) => [$a->layer, $a->y] <=> [$b->layer, $b->y]);
    return $copy;
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'x' => $this->x, 'y' => $this->y, 'pivot' => $this->pivot->toArray(),
      'layer' => $this->layer, 'canvas' => $this->canvas->toArray()];
  }
}
