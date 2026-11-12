<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Alpha falls from opaque to transparent below a sampled line in destination space. */
final readonly class CanvasImageBottomFade
{
  public array $line;

  /** @param list<int|float> $line Normalized heights at uniformly spaced x coordinates, including both ends. */
  public function __construct(array $line, public float $depth)
  {
    $this->line = array_map(static fn(mixed $height): float => CanvasCompositeValues::getNumber($height, 0, 1),
      CanvasCompositeValues::getList($line, 2, 16));
    if (!is_finite($depth) || $depth <= 0.0 || $depth > 1.0) {
      throw new InvalidArgumentException('Canvas image fade depth must be finite and within (0, 1].');
    }
  }

  public static function fromArray(array $data): self
  {
    CanvasCompositeValues::validateKeys($data, ['line', 'depth']);
    return new self(CanvasCompositeValues::getList($data['line'], 2, 16),
      CanvasCompositeValues::getNumber($data['depth'], 0, 1));
  }

  /** Normalized starting height at a normalized x, evaluated after any image flips. */
  public function getStart(float $x): float
  {
    $last = count($this->line) - 1;
    $sample = clamp($x, 0.0, 1.0) * $last;
    $left = min($last - 1, (int) $sample);
    return $this->line[$left] + ($this->line[$left + 1] - $this->line[$left]) * ($sample - $left);
  }

  /** Smoothstep coverage between the opaque surface and the end of the fade. */
  public function getCoverage(float $start, float $y): float
  {
    $t = clamp(($y - $start) / $this->depth, 0.0, 1.0);
    return 1.0 - $t * $t * (3.0 - 2.0 * $t);
  }

  public function toArray(): array
  {
    return ['line' => $this->line, 'depth' => $this->depth];
  }
}
