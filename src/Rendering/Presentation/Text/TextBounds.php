<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use InvalidArgumentException;

/** Signed bearings and possibly empty extents, independent of canvas placement. */
final readonly class TextBounds
{
  public function __construct(public float $x, public float $y, public float $width, public float $height)
  {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value)) {
        throw new InvalidArgumentException('Text bounds must be finite.');
      }
    }
    if ($width < 0 || $height < 0 || $width > CanvasValidation::MAX_EXTENT || $height > CanvasValidation::MAX_EXTENT) {
      throw new InvalidArgumentException('Text bounds require nonnegative extents no greater than 16384.');
    }
  }

  /** @param array<array-key, mixed> $data */
  public static function fromArray(array $data): self
  {
    $fields = ['x', 'y', 'width', 'height'];
    if (array_diff($fields, array_keys($data)) !== [] || array_diff(array_keys($data), $fields) !== []) {
      throw new InvalidArgumentException('Text bounds require exactly x, y, width and height.');
    }
    return new self(self::requireNumber($data['x']), self::requireNumber($data['y']),
      self::requireNumber($data['width']), self::requireNumber($data['height']));
  }

  private static function requireNumber(mixed $value): float
  {
    if (!is_int($value) && !is_float($value)) {
      throw new InvalidArgumentException('Text bounds require numbers without coercion.');
    }
    return (float)$value;
  }

  /** @return array{x: float, y: float, width: float, height: float} */
  public function toArray(): array
  {
    return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
  }
}
