<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** Foot-to-strike offset measured in standing body units; forward x and canvas-down y. */
final readonly class BattlePoseReach
{
  public function __construct(public float $x, public float $y)
  {
    if (!is_finite($x) || $x < 0 || !is_finite($y)) {
      throw new InvalidArgumentException('Pose reach requires finite forward x >= 0 and signed canvas-down y in body units.');
    }
  }

  public static function fromArray(mixed $data, string $where = 'Pose reach'): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['x', 'y']) !== []
      || (!is_int($data['x'] ?? null) && !is_float($data['x'] ?? null))
      || (!is_int($data['y'] ?? null) && !is_float($data['y'] ?? null))) {
      throw new InvalidArgumentException("{$where}: expected exactly numeric x and y in body units.");
    }
    try { return new self((float)$data['x'], (float)$data['y']); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error); }
  }

  /** @return array{x: float, y: float} */
  public function toArray(): array { return ['x' => $this->x, 'y' => $this->y]; }

  /** The presentation owner supplies the current standing-body reference in canvas units. */
  public function getCanvasOffset(float $bodyUnit): Vector2
  {
    if (!is_finite($bodyUnit) || $bodyUnit <= 0 || !is_finite($this->x * $bodyUnit) || !is_finite($this->y * $bodyUnit)) {
      throw new InvalidArgumentException('Pose reach needs a finite positive body unit and finite resolved offsets.');
    }
    return new Vector2($this->x * $bodyUnit, $this->y * $bodyUnit);
  }
}
