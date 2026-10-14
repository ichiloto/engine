<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Detached row replacements for one retained text consumer; not a full snapshot. */
final readonly class ConsolePresentationChanges
{
  /** @var list<array{id: string, layer: int, rows: list<array{row: int, runs: list<PresentationTextRun>}>}> */
  public array $layers;
  /** @var list<string> */
  public array $removedIds;
  /** @var list<string>|null */
  public ?array $order;

  public function __construct(
    public int $width,
    public int $height,
    public bool $reset,
    array $layers = [],
    array $removedIds = [],
    ?array $order = null,
  ) {
    new RendererGridConfig($width, $height, 1, 1);
    if (!array_is_list($layers) || !array_is_list($removedIds) || ($order !== null && !array_is_list($order))) {
      throw new InvalidArgumentException('Retained text changes require layer, removal and order lists.');
    }
    $copy = $ids = [];
    foreach ($layers as $layer) {
      if (!is_array($layer) || !is_string($layer['id'] ?? null) || !is_int($layer['layer'] ?? null)
        || !is_array($layer['rows'] ?? null) || !array_is_list($layer['rows']) || isset($ids[$layer['id']])) {
        throw new InvalidArgumentException('Retained text layer updates require unique IDs, integer priorities and row lists.');
      }
      new PresentationTextLayer($layer['id'], $layer['layer'], []);
      $ids[$layer['id']] = true;
      $rows = $seen = [];
      foreach ($layer['rows'] as $row) {
        if (!is_array($row) || !is_int($row['row'] ?? null) || $row['row'] < 0 || $row['row'] >= $height
          || isset($seen[$row['row']]) || !is_array($row['runs'] ?? null) || !array_is_list($row['runs'])) {
          throw new InvalidArgumentException('Retained text row replacements require unique in-grid rows and run lists.');
        }
        $seen[$row['row']] = true;
        $runs = [];
        foreach ($row['runs'] as $run) {
          if (!$run instanceof PresentationTextRun || $run->row !== $row['row']) {
            throw new InvalidArgumentException('Retained text runs must belong to their replacement row.');
          }
          $run->assertFits($width, $height);
          $runs[] = $run;
        }
        $rows[] = ['row' => $row['row'], 'runs' => $runs];
      }
      $copy[] = ['id' => $layer['id'], 'layer' => $layer['layer'], 'rows' => $rows];
    }
    $removed = [];
    foreach ($removedIds as $id) {
      if (!is_string($id) || isset($removed[$id]) || isset($ids[$id])) {
        throw new InvalidArgumentException('Removed text IDs must be unique and cannot also be updated.');
      }
      new PresentationTextLayer($id, 0, []);
      $removed[$id] = true;
    }
    $ordered = [];
    foreach ($order ?? [] as $id) {
      if (!is_string($id) || isset($ordered[$id]) || isset($removed[$id])) {
        throw new InvalidArgumentException('Retained text order requires unique, nonremoved IDs.');
      }
      new PresentationTextLayer($id, 0, []);
      $ordered[$id] = true;
    }
    $this->layers = $copy;
    $this->removedIds = array_map('strval', array_keys($removed));
    $this->order = $order === null ? null : array_map('strval', array_keys($ordered));
  }
}
