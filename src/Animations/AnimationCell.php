<?php

namespace Ichiloto\Engine\Animations;

/**
 * Represents a single drawable cell in an animation frame.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationCell
{
  /**
   * @param string $symbol The symbol to render.
   * @param int $x The horizontal offset from the target origin.
   * @param int $y The vertical offset from the target origin.
   * @param string|null $color The optional color name.
   */
  public function __construct(
    public string $symbol,
    public int $x,
    public int $y,
    public ?string $color = null,
  )
  {
    $this->symbol = self::normalizeSymbol($symbol);
    $this->color = $color !== null ? strtolower($color) : null;
  }

  /**
   * Hydrates a cell from an array payload.
   *
   * @param array<string, mixed> $data The serialized cell data.
   * @return self
   */
  public static function fromArray(array $data): self
  {
    return new self(
      strval($data['symbol'] ?? ' '),
      intval($data['x'] ?? 0),
      intval($data['y'] ?? 0),
      isset($data['color']) ? strval($data['color']) : null,
    );
  }

  /**
   * Returns the serialized cell payload.
   *
   * @return array{symbol: string, x: int, y: int, color?: string}
   */
  public function toArray(): array
  {
    $payload = [
      'symbol' => $this->symbol,
      'x' => $this->x,
      'y' => $this->y,
    ];

    if ($this->color !== null && $this->color !== '') {
      $payload['color'] = $this->color;
    }

    return $payload;
  }

  /**
   * Normalizes the stored symbol down to one visible grapheme.
   *
   * @param string $symbol The raw symbol.
   * @return string
   */
  private static function normalizeSymbol(string $symbol): string
  {
    if (preg_match('/^\X/u', $symbol, $matches) !== 1) {
      return ' ';
    }

    return $matches[0];
  }
}
