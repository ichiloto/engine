<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

/** Scene-free frame input; the source owns projection, timing, identity and cleanup.
 * World, viewport and optional canvases use their existing provider interfaces.
 */
interface FramePresentationSourceInterface
{
  /** @return list<PresentationSprite> Already camera-relative; retain off-grid sprites for clipping. */
  public function getPresentationSprites(): array;
}
