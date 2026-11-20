<?php
declare(strict_types=1);
namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

/** A composer supplies batch compatibility and carries source ownership onto replacement layers. */
interface CanvasTextBatchScopeInterface
{
  public function getBatchKey(CanvasTextLayer $layer): string;
  /** @param non-empty-list<CanvasTextLayer> $sources */
  public function recordBatch(CanvasTextLayer $result, array $sources): void;
}
