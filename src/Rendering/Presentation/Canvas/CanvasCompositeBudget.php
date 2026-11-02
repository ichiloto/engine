<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** Conservative all-cold work admission; native output reuse may reduce actual work. */
final class CanvasCompositeBudget
{
  public const int MAX_WORK = 268435456;
  public const int MAX_OPERATIONS = 256;
  public const int MAX_PIXELS = 8388608;
  public const int MAX_NODES = 16384;

  public static function getSurfaceLimit(): int
  {
    return PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_COMPOSITE_BUDGET)
      ? self::MAX_OPERATIONS : 8;
  }

  /** Refuse before visiting an oversized collection; full totals follow after typed-list validation. */
  public static function assertSurfaceCount(int $count, string $context = 'Canvas'): void
  {
    $limit = self::getSurfaceLimit();
    if ($count > $limit) {
      throw new CanvasCompositeBudgetExceeded(sprintf('%s composite surfaces %d > %d%s.',
        $context, $count, $limit, $limit === 8 ? '; more require negotiated canvas_composite_budget capability' : ''));
    }
  }

  /** All local and aggregate canvas roots use these same unchanged limits. @param list<CanvasComposite> $composites */
  public static function assertWithin(array $composites, string $context = 'Canvas'): void
  {
    self::assertSurfaceCount(count($composites), $context);
    $pixels = $operations = $nodes = 0;
    foreach ($composites as $composite) {
      $pixels += $composite->width * $composite->height;
      $operations += count($composite->operations);
      foreach ($composite->operations as $operation) { $nodes += count($operation->data['displacement']['offsets'] ?? []); }
    }
    $exceeded = [];
    foreach (['target pixels' => [$pixels, self::MAX_PIXELS], 'operations' => [$operations, self::MAX_OPERATIONS],
      'displacement nodes' => [$nodes, self::MAX_NODES]] as $name => [$actual, $limit]) {
      if ($actual > $limit) { $exceeded[] = $name . ' ' . $actual . ' > ' . $limit; }
    }
    if ($exceeded !== []) {
      throw new CanvasCompositeBudgetExceeded(sprintf('%s exceed the composite budget: %s (%d surfaces, %d pixels, %d operations, %d nodes).',
        $context, implode(', ', $exceeded), count($composites), $pixels, $operations, $nodes));
    }
    self::getWork($composites);
  }

  public static function getWork(array $composites): int
  {
    $work = 0;
    $worlds = 0;
    foreach ($composites as $index => $composite) {
      $work += 2 * $composite->width * $composite->height;
      foreach ($composite->operations as $operation) {
        $data = $operation->data;
        if ($data['type'] === 'world' && ++$worlds > 1) {
          throw new CanvasCompositeBudgetExceeded('A frame can request one active-map overview raster; world operations ' . $worlds . ' > 1.');
        }
        if ($data['type'] === 'stroke') {
          $half = $data['width'] / 2;
          $left = min(array_column($data['points'], 0)) - $half;
          $top = min(array_column($data['points'], 1)) - $half;
          $right = max(array_column($data['points'], 0)) + $half;
          $bottom = max(array_column($data['points'], 1)) + $half;
        } else {
          $rect = $data['destination'];
          $left = $rect['x']; $top = $rect['y'];
          $right = $left + $rect['width']; $bottom = $top + $rect['height'];
        }
        $pixels = (int)(max(0, min($composite->width, ceil($right)) - max(0, floor($left)))
          * max(0, min($composite->height, ceil($bottom)) - max(0, floor($top))));
        $cost = match ($data['type']) {
          'image', 'world' => 16 + (isset($data['displacement']) ? 8 : 0),
          'fill' => 4, 'stroke' => 4 + count($data['points']) + (isset($data['dash']) ? 4 : 0),
        };
        foreach ([$data['masks'], $data['displacement']['masks'] ?? []] as $masks) {
          if ($masks === []) { continue; }
          $cost += 2;
          foreach ($masks as $mask) {
            $cost += match ($mask['type']) {
              'polygon' => array_sum(array_map(count(...), $mask['contours'])), 'ellipse' => 8, 'image_alpha' => 16,
            };
          }
        }
        $work += $pixels * $cost;
        if ($work > self::MAX_WORK) {
          throw new CanvasCompositeBudgetExceeded(sprintf('Canvas composites exceed the bounded cold raster work budget: %d > %d at surface %d of %d (%s).',
            $work, self::MAX_WORK, $index + 1, count($composites), $composite->id));
        }
      }
    }
    return $work;
  }
}
