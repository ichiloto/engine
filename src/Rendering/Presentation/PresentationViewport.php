<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
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
    public ?string $worldId = null,
    public int $worldOriginX = 0,
    public int $worldOriginY = 0,
    /** The world's tile animation counter; each animated tile shows frames[tileFrame % count]. */
    public int $tileFrame = 0,
  ) {
    if ($tileFrame < 0) {
      throw new InvalidArgumentException('Viewport tileFrame must be nonnegative.');
    }
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
    if (($worldId !== null && ($worldId === '' || strlen($worldId) > 256 || preg_match('//u', $worldId) !== 1))
      || ($worldId === null && ($worldOriginX !== 0 || $worldOriginY !== 0))) {
      throw new InvalidArgumentException('A signed world origin requires a nonempty UTF-8 world ID of at most 256 bytes.');
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
  public function assertMembers(array|ConsolePresentationSnapshot|ConsolePresentationChanges $text, array $sprites, array $tiles = []): void
  {
    if ($text instanceof ConsolePresentationChanges) {
      if (array_intersect($this->textLayerIds, $text->removedIds) !== []
        || ($text->order !== null && array_diff($this->textLayerIds, $text->order) !== [])) {
        throw new InvalidArgumentException('Viewport membership must reference retained text layers.');
      }
      // Incremental rows omit unchanged layers; complete membership belongs to the scene.
      $text = array_map(static fn(string $id): array => ['id' => $id], $this->textLayerIds);
    } elseif ($text instanceof ConsolePresentationSnapshot) { $text = $text->textLayers; }
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
      'spriteIds' => $this->spriteIds,
      ...($this->worldId === null ? [] : ['worldId' => $this->worldId,
        'worldOrigin' => ['column' => $this->worldOriginX, 'row' => $this->worldOriginY]]),
      ...($this->tileFrame === 0 ? [] : ['tileFrame' => $this->tileFrame])];
  }
}
