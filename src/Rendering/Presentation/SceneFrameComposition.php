<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use InvalidArgumentException;

/** Typed scene inputs, independent of any renderer's upload cursor or frame acknowledgements. */
final readonly class SceneFrameComposition
{
  /** @var list<PresentationSprite> */
  public array $sprites;

  /** A null text snapshot denotes a complete canvas replacement, not a field overlay.
   * @param list<PresentationSprite> $sprites
   */
  public function __construct(
    public ConsolePresentationSnapshot|ConsolePresentationChanges|null $snapshot,
    array $sprites = [],
    public ?PresentationViewport $viewport = null,
    public ?PresentationWorld $world = null,
    public ?PresentationCanvas $canvas = null,
    public ?PresentationCanvas $screenOverlay = null,
  ) {
    if ($snapshot === null && ($canvas === null || $sprites !== [] || $viewport !== null || $world !== null)) {
      throw new InvalidArgumentException('A canvas scene requires one replacement canvas and no field inputs.');
    }
    $this->sprites = PresentationSprite::orderedList($sprites);
    $viewport?->assertMembers($snapshot, $this->sprites);
  }

  /** Retained-frame ownership and reliable delivery stay with the caller's existing presenter. */
  public function prepareFrame(RendererPresentation $presentation): RetainedFrame
  {
    return $this->snapshot === null ? $presentation->prepareCanvas($this->canvas)
      : $presentation->prepareFrame($this->snapshot, $this->sprites, $this->viewport, $this->world, $this->canvas);
  }
}
