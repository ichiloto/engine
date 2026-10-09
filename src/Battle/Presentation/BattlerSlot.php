<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** Authored pivot destination and contain limits, never terminal coordinates. */
final readonly class BattlerSlot
{
  public function __construct(
    public float $x,
    public float $y,
    public float $width,
    public float $height,
    public float $displayScale = 1.0,
  ) {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value) || $value < 0 || $value > 16384) {
        throw new InvalidArgumentException('Battler slot geometry must be finite and within 0..16384.');
      }
    }
    if ($width <= 0 || $height <= 0) {
      throw new InvalidArgumentException('Battler slot contain limits must be positive.');
    }
    if (!is_finite($displayScale) || $displayScale <= 0 || $displayScale > 64) {
      throw new InvalidArgumentException('Battler slot display scale must be finite and within 0..64.');
    }
  }

  public function place(BattlerArtwork $art, ?float $displayWidth = null): CanvasRectangle
  {
    if ($displayWidth !== null && (!is_finite($displayWidth) || $displayWidth <= 0 || $displayWidth > 16384)) {
      throw new InvalidArgumentException('Battler display width must be finite and within 0..16384.');
    }
    $scale = $displayWidth === null ? min($this->width / $art->width, $this->height / $art->height)
      : $displayWidth / $art->width;
    return $this->placeAtScale($art, $scale);
  }

  /** Apply slot depth once to the calibrated pixel scale, preserving the ground pivot. */
  public function placeAtScale(BattlerArtwork $art, float $scale): CanvasRectangle
  {
    if (!is_finite($scale) || $scale <= 0) {
      throw new InvalidArgumentException('Battler artwork scale must be finite and positive.');
    }
    $scale *= $this->displayScale;
    return new CanvasRectangle($this->x - $art->pivotX * $scale, $this->y - $art->pivotY * $scale,
      $art->width * $scale, $art->height * $scale);
  }

  public static function fromArray(mixed $data, string $source = 'graphical placement'): self
  {
    $required = ['x', 'y', 'width', 'height'];
    $fields = [...$required, 'displayScale'];
    if (!is_array($data) || array_diff(array_keys($data), $fields) !== []
      || array_diff($required, array_keys($data)) !== []) {
      throw new InvalidArgumentException("{$source}: expected x, y, width and height with optional displayScale.");
    }
    foreach ($fields as $field) {
      if (!array_key_exists($field, $data)) { continue; }
      if (!is_int($data[$field]) && !is_float($data[$field])) {
        throw new InvalidArgumentException("{$source}.{$field}: expected a finite number.");
      }
    }
    try {
      return new self($data['x'], $data['y'], $data['width'], $data['height'], $data['displayScale'] ?? 1.0);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$source}: {$error->getMessage()}", previous: $error);
    }
  }

  /** @return list<BattlerSlot> */
  public static function getValidatedList(array $slots): array
  {
    if (!array_is_list($slots) || count($slots) > 64) {
      throw new InvalidArgumentException('Battle slots must be a list of at most 64 entries.');
    }
    $copy = [];
    foreach ($slots as $slot) {
      if (!$slot instanceof self) { throw new InvalidArgumentException('Battle slots must be typed BattlerSlot entries.'); }
      $copy[] = $slot;
    }
    return $copy;
  }
}
