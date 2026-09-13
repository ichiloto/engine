<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Compact terrain intent; cells are values, never actor sprite objects. */
final readonly class PresentationTileBatch
{
  public const int MAX_BATCHES = 64;
  public const int MAX_SOURCES = 256;
  public const int MAX_FRAME_SOURCES = 4096;
  public const int MAX_CELLS = 32768;
  /** @var list<SpriteSourceRect> */
  public array $sources;
  /** @var list<array{column: int, row: int, source: int}> */
  public array $cells;

  /** @param list<SpriteSourceRect> $sources @param list<array{column: int, row: int, source: int}> $cells */
  public function __construct(public string $id, public string $asset, public int $layer,
    array $sources, array $cells)
  {
    SpriteValidation::validateAssetPath($asset);
    SpriteValidation::validateSigned32BitRange($layer);
    if ($id === '' || strlen($id) > 256 || preg_match('//u', $id) !== 1
      || strlen($asset) > 4096 || strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'png'
      || !array_is_list($sources) || count($sources) < 1 || count($sources) > self::MAX_SOURCES
      || !array_is_list($cells) || count($cells) > self::MAX_CELLS) {
      throw new InvalidArgumentException('Invalid tile batch identity, PNG asset or source/cell catalog bounds.');
    }
    $sourceCopy = $cellCopy = [];
    foreach ($sources as $source) {
      if (!$source instanceof SpriteSourceRect) { throw new InvalidArgumentException('Tile sources must be typed source rectangles.'); }
      $sourceCopy[] = $source;
    }
    $seen = [];
    foreach ($cells as $cell) {
      if (!is_array($cell) || count($cell) !== 3) { throw new InvalidArgumentException('Invalid tile cell fields.'); }
      foreach (['column', 'row', 'source'] as $field) {
        if (!is_int($cell[$field] ?? null) || $cell[$field] < 0 || $cell[$field] > 4294967295) {
          throw new InvalidArgumentException('Tile cell coordinates and source indices must be unsigned 32-bit integers.');
        }
      }
      $key = $cell['row'] . ':' . $cell['column'];
      if (!isset($sources[$cell['source']]) || isset($seen[$key])) {
        throw new InvalidArgumentException('Tile cell source index is invalid or destination is duplicated.');
      }
      $seen[$key] = true;
      $cellCopy[] = ['column' => $cell['column'], 'row' => $cell['row'], 'source' => $cell['source']];
    }
    $this->sources = $sourceCopy;
    $this->cells = $cellCopy;
  }

  public function assertWithin(RendererGridConfig $grid): void
  {
    foreach ($this->cells as $cell) {
      if ($cell['column'] >= $grid->columns || $cell['row'] >= $grid->rows) {
        throw new InvalidArgumentException('Tile cell lies outside the fixed renderer grid.');
      }
    }
  }

  /** @param list<self> $batches @return list<self> */
  public static function orderedList(array $batches): array
  {
    if (!array_is_list($batches) || count($batches) > self::MAX_BATCHES) {
      throw new InvalidArgumentException('A frame accepts at most 64 tile batches.');
    }
    $ids = $copy = [];
    $sources = $cells = 0;
    foreach ($batches as $batch) {
      if (!$batch instanceof self || isset($ids[$batch->id])) {
        throw new InvalidArgumentException('Tile batches must be typed with unique IDs.');
      }
      $ids[$batch->id] = true;
      $copy[] = $batch;
      $sources += count($batch->sources);
      $cells += count($batch->cells);
    }
    if ($sources > self::MAX_FRAME_SOURCES || $cells > self::MAX_CELLS) {
      throw new InvalidArgumentException('Frame exceeds aggregate tile source/cell limits.');
    }
    usort($copy, static fn(self $a, self $b) => $a->layer <=> $b->layer);
    return $copy;
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'asset' => $this->asset, 'layer' => $this->layer,
      'sources' => array_map(static fn(SpriteSourceRect $source) => $source->toArray(), $this->sources),
      'cells' => $this->cells];
  }
}
