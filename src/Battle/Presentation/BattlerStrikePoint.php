<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** An artist-owned contact point in the current visible body, independent of image dimensions. */
final readonly class BattlerStrikePoint
{
  public function __construct(public float $x, public float $y)
  {
    if (!is_finite($x) || !is_finite($y) || min($x, $y) < 0 || max($x, $y) > 1) {
      throw new InvalidArgumentException('Battler strike point x and y must be finite normalized values from zero to one.');
    }
  }

  public static function fromArray(mixed $data, string $where = 'Battler strike point'): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['x', 'y']) !== []
      || (!is_int($data['x'] ?? null) && !is_float($data['x'] ?? null))
      || (!is_int($data['y'] ?? null) && !is_float($data['y'] ?? null))) {
      throw new InvalidArgumentException("{$where}: expected exactly numeric normalized x and y.");
    }
    try { return new self((float)$data['x'], (float)$data['y']); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error); }
  }

  /** @return array{x: float, y: float} */
  public function toArray(): array { return ['x' => $this->x, 'y' => $this->y]; }

  /** Resolve from current visible drawn bounds and the image's actual mirrors, never cached PNG metadata. */
  public function getCanvasPosition(CanvasRectangle $visible, bool $flipX = false, bool $flipY = false): Vector2
  {
    return new Vector2($visible->x + ($flipX ? 1 - $this->x : $this->x) * $visible->width,
      $visible->y + ($flipY ? 1 - $this->y : $this->y) * $visible->height);
  }
}
