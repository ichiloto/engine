<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * The ground battlers stand on in a graphical battle, in shared canvas units:
 * the band their feet may touch. `top` is the far edge, just below the
 * background's horizon or walkable edge; `bottom` is the near edge, nearest
 * the camera; `perspective` is the display scale of a battler standing on the
 * far edge relative to one on the near edge, applied linearly between them.
 *
 * Every field is optional. An arena declares what its background shows and
 * inherits the rest from the layout's theme ground; the layout's own missing
 * values have documented defaults ({@see BattleCanvasLayout::getGround()}).
 * The Terminal battle never reads it.
 */
final readonly class BattleGround
{
  public function __construct(
    public ?float $top = null,
    public ?float $bottom = null,
    public ?float $perspective = null,
  ) {
    foreach ([$top, $bottom] as $edge) {
      if ($edge !== null && (!is_finite($edge) || $edge < 0 || $edge > 16384)) {
        throw new InvalidArgumentException('Battle ground edges must be finite canvas positions within 0..16384.');
      }
    }
    if ($top !== null && $bottom !== null && $top >= $bottom) {
      throw new InvalidArgumentException('A battle ground\'s far edge (top) must lie above its near edge (bottom).');
    }
    if ($perspective !== null && (!is_finite($perspective) || $perspective <= 0 || $perspective > 4)) {
      throw new InvalidArgumentException('Battle ground perspective must be a finite scale within 0..4.');
    }
  }

  /** This ground's declared values over another's: what an arena keeps from the theme. */
  public function getOver(?self $base): self
  {
    return $base === null ? $this
      : new self($this->top ?? $base->top, $this->bottom ?? $base->bottom, $this->perspective ?? $base->perspective);
  }

  /** @return array{top?: float, bottom?: float, perspective?: float} */
  public function toArray(): array
  {
    return array_filter(['top' => $this->top, 'bottom' => $this->bottom, 'perspective' => $this->perspective],
      static fn(?float $value): bool => $value !== null);
  }

  /** Reads `top`, `bottom` and `perspective`, each optional, as authored data. */
  public static function fromArray(mixed $data, string $source = 'ground'): self
  {
    $fields = ['top', 'bottom', 'perspective'];
    if (!is_array($data) || array_diff(array_keys($data), $fields) !== []) {
      throw new InvalidArgumentException("{$source}: expected top, bottom and perspective, each optional.");
    }
    foreach ($fields as $field) {
      if (array_key_exists($field, $data) && !is_int($data[$field]) && !is_float($data[$field])) {
        throw new InvalidArgumentException("{$source}.{$field}: expected a finite number.");
      }
    }
    try {
      return new self(isset($data['top']) ? (float) $data['top'] : null, isset($data['bottom']) ? (float) $data['bottom'] : null,
        isset($data['perspective']) ? (float) $data['perspective'] : null);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$source}: {$error->getMessage()}", previous: $error);
    }
  }
}
