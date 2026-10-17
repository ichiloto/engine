<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

/** Screen-space HUD over a retained world, never a replacement for its grid or sprites. */
interface CanvasOverlayProviderInterface
{
  /** Refresh the owned Terminal contribution at normal and blocked-frame boundaries. */
  public function renderPresentationOverlay(): void;

  public function getPresentationOverlay(int $width, int $height): ?PresentationCanvas;

  /** Terminal layers replaced or suppressed by the active graphical presentation policy.
   * Called even when this frame has no canvas (for example an intentionally hidden HUD).
   * Return only layers whose graphical ownership or suppression is established.
   * @return list<string>
   */
  public function getExcludedOverlayLayers(): array;
}
