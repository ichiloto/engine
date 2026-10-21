<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/**
 * An arena's light on the theme's contact shadow: how strong it falls there
 * (darker in a mine, softer on bright grass) and, optionally, its colour.
 * Authored in arenas.php as `'shadow' => ['strength' => 1.3, 'color' => '#1a120c']`.
 */
final readonly class BattleShadowLight
{
  public function __construct(public float $strength = 1.0, public ?PresentationColor $color = null)
  {
    if (!is_finite($strength) || $strength < 0 || $strength > 4) {
      throw new InvalidArgumentException('An arena\'s shadow strength must be within 0..4 of the theme\'s.');
    }
  }

  public static function fromArray(mixed $data, string $where): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['strength', 'color']) !== []) {
      throw new InvalidArgumentException("{$where}: expected strength and color only.");
    }
    $strength = $data['strength'] ?? 1.0;
    if (!is_int($strength) && !is_float($strength)) {
      throw new InvalidArgumentException("{$where}.strength: expected a number.");
    }
    $color = $data['color'] ?? null;
    if ($color !== null && (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1)) {
      throw new InvalidArgumentException("{$where}.color: expected a colour written #rrggbb.");
    }
    try {
      return new self((float) $strength, $color === null ? null
        : PresentationColor::rgb(hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))));
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error);
    }
  }

  /** @return array{strength?: float, color?: string} */
  public function toArray(): array
  {
    $color = $this->color?->toArray();
    return array_filter(['strength' => $this->strength === 1.0 ? null : $this->strength,
      'color' => $color === null ? null : sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b'])],
      static fn($value): bool => $value !== null);
  }
}
