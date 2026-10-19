<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget;
use InvalidArgumentException;

/** Typed scene inputs, independent of any renderer's upload cursor or frame acknowledgements. */
final readonly class SceneFrameComposition
{
  /** @var list<PresentationSprite> */
  public array $sprites;
  /** @var list<PresentationWorldCanvas> */
  public array $worldCanvases;

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
    array $worldCanvases = [],
  ) {
    if ($snapshot === null && ($canvas === null || $sprites !== [] || $viewport !== null || $worldCanvases !== [])) {
      throw new InvalidArgumentException('A canvas scene requires one replacement canvas and no field inputs.');
    }
    $this->sprites = PresentationSprite::orderedList($sprites);
    $this->worldCanvases = PresentationWorldCanvas::getOrderedList($worldCanvases);
    PresentationSubjectOrder::getOrderedSubjects($this->sprites, $this->worldCanvases);
    if ($this->worldCanvases !== [] && ($world === null || $viewport?->worldId !== $world->id)) {
      throw new InvalidArgumentException('World canvases require their selected field world and viewport.');
    }
    $viewport?->assertMembers($snapshot, $this->sprites, worldCanvases: $this->worldCanvases);
    $composed = $screenOverlay === null ? $canvas : PresentationCanvas::composeOverlay($canvas, $screenOverlay);
    $composed?->assertWorldReferences($world);
    if ($snapshot === null && $world !== null && ($composed?->getReferencedWorldIds() ?? []) === []) {
      throw new InvalidArgumentException('An exclusive canvas may retain a world only as a referenced raster source.');
    }
    foreach ($this->worldCanvases as $group) { $group->canvas->assertWorldReferences($world); }
    CanvasFrameBudget::assertCanvases([...($composed === null ? [] : [$composed]),
      ...array_map(static fn($group) => $group->canvas, $this->worldCanvases)]);
  }

  /** Retained-frame ownership and reliable delivery stay with the caller's existing presenter. */
  public function prepareFrame(RendererPresentation $presentation): RetainedFrame
  {
    return $this->snapshot === null ? $presentation->prepareCanvas($this->canvas, $this->world)
      : $presentation->prepareFrame($this->snapshot, $this->sprites, $this->viewport, $this->world, $this->canvas, $this->worldCanvases);
  }
}
