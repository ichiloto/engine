<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** One fixed map cell or one stable field-object identity. */
final readonly class FieldEffectAnchor
{
  private function __construct(public ?Vector2 $cell, public ?string $objectId) {}

  /** Runtime cinematic positions can be off-map; authored map cells remain strict. */
  public static function createAtPosition(Vector2 $position): self
  {
    if (!is_finite((float)$position->x) || !is_finite((float)$position->y)) {
      throw new InvalidArgumentException('A field effect position must be finite.');
    }
    return new self(clone $position, null);
  }

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || count($data) !== 1) {
      throw new InvalidArgumentException('A field effect anchor names one cell or object.');
    }
    if (isset($data['object']) && is_string($data['object']) && trim($data['object']) !== ''
      && !str_contains($data['object'], "\0")) {
      return new self(null, $data['object']);
    }
    $cell = $data['cell'] ?? null;
    if (!is_array($cell) || count($cell) !== 2 || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null)
      || $cell['x'] < 0 || $cell['y'] < 0) {
      throw new InvalidArgumentException('A field effect cell needs nonnegative integer x and y.');
    }
    return new self(new Vector2($cell['x'], $cell['y']), null);
  }
}
