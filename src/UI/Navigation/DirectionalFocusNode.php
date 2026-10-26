<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

use Ichiloto\Engine\UI\Navigation\FocusDirection;
use InvalidArgumentException;

/** Immutable navigation snapshot. Disabled nodes remain focusable. */
final readonly class DirectionalFocusNode implements DirectionalFocusNodeInterface
{
  public function __construct(
    public string $id,
    public bool $enabled = true,
    public ?string $up = null,
    public ?string $right = null,
    public ?string $down = null,
    public ?string $left = null,
  ) {
    if (trim($id) === '') {
      throw new InvalidArgumentException('A directional focus node requires a nonempty ID.');
    }
    foreach (['up' => $up, 'right' => $right, 'down' => $down, 'left' => $left] as $direction => $neighbourId) {
      if ($neighbourId !== null && trim($neighbourId) === '') {
        throw new InvalidArgumentException("The {$direction} neighbour of {$id} requires a nonempty ID or null.");
      }
    }
  }

  public function getNeighbourId(FocusDirection $direction): ?string
  {
    return match ($direction) {
      FocusDirection::UP => $this->up,
      FocusDirection::RIGHT => $this->right,
      FocusDirection::DOWN => $this->down,
      FocusDirection::LEFT => $this->left,
    };
  }
}
