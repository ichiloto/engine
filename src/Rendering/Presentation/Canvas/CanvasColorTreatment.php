<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

/** Derived parent-after-child treatment; authored transforms are validated at their own boundary.
 * Composition retains arithmetic noise exactly as the renderer does, without readmitting a derived map.
 */
final readonly class CanvasColorTreatment
{
  /** @param list<list<float>> $matrix @param list<float> $offset */
  private function __construct(public array $matrix, public array $offset) {}

  public static function getEffective(?self $parent, ?CanvasColorTransform $own): ?self
  {
    if ($own === null) { return $parent; }
    $matrix = $own->matrix;
    $offset = $own->offset;
    if ($parent !== null) {
      $matrix = $offset = [];
      for ($row = 0; $row < 3; $row++) {
        $matrix[$row] = [];
        for ($column = 0; $column < 3; $column++) {
          $matrix[$row][$column] = 0.0;
          for ($k = 0; $k < 3; $k++) {
            $matrix[$row][$column] += $parent->matrix[$row][$k] * $own->matrix[$k][$column];
          }
        }
        $offset[$row] = $parent->offset[$row];
        for ($k = 0; $k < 3; $k++) { $offset[$row] += $parent->matrix[$row][$k] * $own->offset[$k]; }
      }
    }
    if ($offset == [0.0, 0.0, 0.0] && $matrix == [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]) { return null; }
    return new self($matrix, $offset);
  }

  /** The renderer keys all twelve f64 values, with signed zero normalized. */
  public function getCacheKey(): string
  {
    $key = '';
    foreach ([...$this->matrix[0], ...$this->matrix[1], ...$this->matrix[2], ...$this->offset] as $value) {
      $key .= bin2hex(pack('E', $value == 0 ? 0.0 : $value));
    }
    return $key;
  }
}
