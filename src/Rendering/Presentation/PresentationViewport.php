<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** One explicitly selected group, transformed before the native window fit. */
final readonly class PresentationViewport
{
  public const float MAX_SCALE = 8.0;

  /** @param list<string> $textLayerIds @param list<string> $spriteIds @param list<string> $tileBatchIds */
  public function __construct(
    public float $scale,
    public float $x,
    public float $y,
    public CanvasRectangle $clipRect,
    public array $textLayerIds = [],
    public array $spriteIds = [],
    public array $tileBatchIds = [],
  ) {
    if (!is_finite($scale) || $scale <= 0 || $scale > self::MAX_SCALE
      || !is_finite($x) || !is_finite($y) || $x < 0 || $y < 0) {
      throw new InvalidArgumentException('Viewport scale must be finite, positive and at most 8; its origin must be finite and nonnegative.');
    }
    foreach ([$textLayerIds, $spriteIds, $tileBatchIds] as $ids) {
      if (!array_is_list($ids)) { throw new InvalidArgumentException('Viewport membership must be a list.'); }
      $seen = [];
      foreach ($ids as $id) {
        if (!is_string($id) || $id === '' || strlen($id) > 256 || preg_match('//u', $id) !== 1 || isset($seen[$id])) {
          throw new InvalidArgumentException('Viewport members require unique, nonempty UTF-8 IDs of at most 256 bytes.');
        }
        $seen[$id] = true;
      }
    }
  }

  public function assertWithin(RendererGridConfig $grid): void
  {
    $width = $grid->columns * $grid->cellWidth;
    $height = $grid->rows * $grid->cellHeight;
    $this->clipRect->assertWithin($width, $height);
    if ($this->x > $width || $this->y > $height) {
      throw new InvalidArgumentException('Viewport origin must lie within the session surface.');
    }
  }

  /** @param list<PresentationTextLayer> $text @param list<PresentationSprite> $sprites @param list<PresentationTileBatch> $tiles */
  public function assertMembers(array $text, array $sprites, array $tiles): void
  {
    foreach ([[$this->textLayerIds, $text], [$this->spriteIds, $sprites], [$this->tileBatchIds, $tiles]] as [$ids, $items]) {
      if (array_diff($ids, array_column($items, 'id')) !== []) {
        throw new InvalidArgumentException('Viewport membership must reference items in the same frame.');
      }
    }
  }

  public function toArray(): array
  {
    return ['scale' => $this->scale, 'origin' => ['x' => $this->x, 'y' => $this->y],
      'clipRect' => $this->clipRect->toArray(), 'textLayerIds' => $this->textLayerIds,
      'spriteIds' => $this->spriteIds, 'tileBatchIds' => $this->tileBatchIds];
  }
}
