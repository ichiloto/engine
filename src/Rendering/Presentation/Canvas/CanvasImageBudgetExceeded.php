<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use RuntimeException;

/** A measured resource defect; callers retain supported fallback presentation. */
final class CanvasImageBudgetExceeded extends RuntimeException
{
  /** @param array<string, int> $sources
   * @param array<string, int> $chargedSources
   * @param array<string, int> $regions
   */
  public function __construct(array $sources, array $chargedSources, array $regions, bool $regionBudget)
  {
    $sourceOverflow = count($sources) > 1024 || array_sum($chargedSources) > CanvasImagePreflight::MAX_BYTES;
    arsort($sources);
    arsort($regions);
    $largest = static fn(array $entries): string => implode(', ', array_map(
      static fn(string $name, int $bytes): string => $name . '=' . $bytes . ' bytes',
      array_keys(array_slice($entries, 0, 5, true)), array_values(array_slice($entries, 0, 5, true))));
    parent::__construct(sprintf(
      'Canvas %s exceed the native 64 MiB / %s limit. PNGs: %d/1024; charged sources: %d/%d bytes (%s); prepared regions: %d/4096, %d/%d bytes. Largest PNG sources: %s. Largest prepared regions: %s.',
      $sourceOverflow ? 'PNG sources' : 'prepared regions', $sourceOverflow ? '1024-image' : '4096-region',
      count($sources), array_sum($chargedSources), CanvasImagePreflight::MAX_BYTES,
      $regionBudget ? 'whole-source consumers' : 'legacy whole-PNG accounting',
      count($regions), array_sum($regions), CanvasImagePreflight::MAX_BYTES,
      $largest($sources), $largest($regions)));
  }
}
