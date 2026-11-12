<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/**
 * An authored formation place, independent of any background: `x` is the
 * canvas column the battler's feet stand on, and `depth` is how far back from
 * the arena ground's near edge they stand, in canvas units. Every arena stands
 * the same formation on its own ground ({@see BattleStaging}), so a troop or
 * party is placed once for every arena. Width and height are the movement and
 * legacy contain limits; displayScale is the slot's depth treatment, applied
 * once after body calibration. Never terminal coordinates.
 */
final readonly class BattleStageSlot
{
  public function __construct(
    public float $x,
    public float $depth,
    public float $width,
    public float $height,
    public float $displayScale = 1.0,
  ) {
    // The canvas slot's own validation covers the shared limits.
    new BattlerSlot($x, $depth, $width, $height, $displayScale);
  }

  /** This place standing on a ground at a depth compression, with the ground's perspective. */
  public function getSlotOn(BattleGroundBand $ground, float $compression = 1.0): BattlerSlot
  {
    if (!is_finite($compression) || $compression <= 0 || $compression > 1) {
      throw new InvalidArgumentException('Stage depth compression must be finite within 0..1.');
    }
    $footY = $ground->getFootY($this->depth * $compression);
    return new BattlerSlot($this->x, $footY, $this->width, $this->height,
      $this->displayScale * $ground->getDepthScale($footY));
  }

  /** @return array{x: float, depth: float, width: float, height: float, displayScale?: float} */
  public function toArray(): array
  {
    return ['x' => $this->x, 'depth' => $this->depth, 'width' => $this->width, 'height' => $this->height,
      ...($this->displayScale === 1.0 ? [] : ['displayScale' => $this->displayScale])];
  }

  public static function fromArray(mixed $data, string $source = 'graphical placement'): self
  {
    $required = ['x', 'depth', 'width', 'height'];
    $fields = [...$required, 'displayScale'];
    if (is_array($data) && array_key_exists('y', $data) && !array_key_exists('depth', $data)) {
      throw new InvalidArgumentException("{$source}: y is a fixed canvas height; a placement stands at a depth on each "
        . 'arena\'s ground instead. Place it again (Align, or drag it) to give it x and depth.');
    }
    if (!is_array($data) || array_diff(array_keys($data), $fields) !== []
      || array_diff($required, array_keys($data)) !== []) {
      throw new InvalidArgumentException("{$source}: expected x, depth, width and height with optional displayScale.");
    }
    foreach ($fields as $field) {
      if (array_key_exists($field, $data) && !is_int($data[$field]) && !is_float($data[$field])) {
        throw new InvalidArgumentException("{$source}.{$field}: expected a finite number.");
      }
    }
    try {
      return new self($data['x'], $data['depth'], $data['width'], $data['height'], $data['displayScale'] ?? 1.0);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$source}: {$error->getMessage()}", previous: $error);
    }
  }

  /** @return list<self> */
  public static function getValidatedList(array $slots): array
  {
    if (!array_is_list($slots) || count($slots) > 64) {
      throw new InvalidArgumentException('Stage slots must be a list of at most 64 entries.');
    }
    foreach ($slots as $slot) {
      if (!$slot instanceof self) { throw new InvalidArgumentException('Stage slots must be typed BattleStageSlot entries.'); }
    }
    return $slots;
  }
}
