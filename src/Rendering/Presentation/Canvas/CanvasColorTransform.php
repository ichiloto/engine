<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Alpha-preserving affine RGB treatment, bounded over the complete straight-RGB cube. */
final readonly class CanvasColorTransform
{
  /** @var list<list<float>> */
  public array $matrix;
  /** @var list<float> */
  public array $offset;
  // Only arithmetic noise may leave the unit cube; substantive clipping breaks source-over equivalence.
  private const float ROUNDING_TOLERANCE = 1.0e-12;

  /** @param list<list<int|float>> $matrix @param list<int|float> $offset */
  public function __construct(array $matrix, array $offset)
  {
    if (!array_is_list($matrix) || count($matrix) !== 3) {
      throw new InvalidArgumentException('Canvas colour matrix requires three RGB rows.');
    }
    $this->offset = self::copyTriplet($offset);
    $rows = [];
    foreach ($matrix as $index => $row) {
      if (!is_array($row)) { throw new InvalidArgumentException('Canvas colour matrix requires numeric RGB rows.'); }
      $row = self::copyTriplet($row);
      $minimum = $maximum = $this->offset[$index];
      foreach ($row as $coefficient) {
        $minimum += min(0.0, $coefficient);
        $maximum += max(0.0, $coefficient);
      }
      if ($minimum < -self::ROUNDING_TOLERANCE || $maximum > 1.0 + self::ROUNDING_TOLERANCE) {
        throw new InvalidArgumentException('Canvas colour transform must preserve the entire RGB unit cube.');
      }
      $rows[] = $row;
    }
    $this->matrix = $rows;
  }

  private static function copyTriplet(array $values): array
  {
    if (!array_is_list($values) || count($values) !== 3) {
      throw new InvalidArgumentException('Canvas colour vectors require three finite numbers.');
    }
    $copy = [];
    foreach ($values as $value) {
      if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
        throw new InvalidArgumentException('Canvas colour vectors require finite numbers without coercion.');
      }
      $copy[] = (float)$value;
    }
    return $copy;
  }

  /** Evaluate straight RGB; the caller retains the original alpha, including zero. */
  public function transformRgb(float $red, float $green, float $blue): array
  {
    foreach ([$red, $green, $blue] as $component) {
      if (!is_finite($component) || $component < 0 || $component > 1) {
        throw new InvalidArgumentException('Canvas colour input must be finite RGB in 0..1.');
      }
    }
    return array_map(fn(array $row, float $offset): float =>
      max(0.0, min(1.0, $row[0] * $red + $row[1] * $green + $row[2] * $blue + $offset)),
      $this->matrix, $this->offset);
  }

  public function toArray(): array
  {
    return ['matrix' => $this->matrix, 'offset' => $this->offset];
  }
}
