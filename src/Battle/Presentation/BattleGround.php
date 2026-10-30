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
 * Alternatively `scaleReference` declares a measured horizon and upright
 * scale, keeping the shared body reference at its reference row.
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
    public ?BattleGroundScale $scaleReference = null,
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
    if ($perspective !== null && $scaleReference !== null) {
      throw new InvalidArgumentException('A battle ground declares either perspective or a measured scaleReference.');
    }
  }

  /** This ground's declared values over another's: what an arena keeps from the theme. */
  public function getOver(?self $base): self
  {
    if ($base === null) { return $this; }
    $scale = $this->scaleReference ?? ($this->perspective === null ? $base->scaleReference : null);
    return new self($this->top ?? $base->top, $this->bottom ?? $base->bottom,
      $scale === null ? ($this->perspective ?? $base->perspective) : null, $scale);
  }

  /** @return array{top?: float, bottom?: float, perspective?: float, scaleReference?: array} */
  public function toArray(): array
  {
    return array_filter(['top' => $this->top, 'bottom' => $this->bottom, 'perspective' => $this->perspective,
      'scaleReference' => $this->scaleReference?->toArray()], static fn(mixed $value): bool => $value !== null);
  }

  /** Reads the optional standing band and one authored depth-scale model. */
  public static function fromArray(mixed $data, string $source = 'ground'): self
  {
    $fields = ['top', 'bottom', 'perspective', 'scaleReference'];
    if (!is_array($data) || array_diff(array_keys($data), $fields) !== []) {
      throw new InvalidArgumentException("{$source}: expected optional top, bottom and perspective or scaleReference.");
    }
    foreach ($fields as $field) {
      if ($field === 'scaleReference') { continue; }
      if (array_key_exists($field, $data) && !is_int($data[$field]) && !is_float($data[$field])) {
        throw new InvalidArgumentException("{$source}.{$field}: expected a finite number.");
      }
    }
    try {
      return new self(isset($data['top']) ? (float) $data['top'] : null, isset($data['bottom']) ? (float) $data['bottom'] : null,
        isset($data['perspective']) ? (float) $data['perspective'] : null,
        array_key_exists('scaleReference', $data) ? BattleGroundScale::fromArray($data['scaleReference'], "{$source}.scaleReference") : null);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$source}: {$error->getMessage()}", previous: $error);
    }
  }
}
