<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** An artist's measured upright scale on a planar ground, in shared canvas coordinates. */
final readonly class BattleGroundScale
{
  public function __construct(
    public float $horizonY,
    public float $referenceY,
    public float $pixelsPerUnit,
    public ?float $vanishingX = null,
  ) {
    foreach ([$horizonY, $referenceY, $vanishingX] as $coordinate) {
      if ($coordinate !== null && (!is_finite($coordinate) || abs($coordinate) > 16384)) {
        throw new InvalidArgumentException('Ground scale coordinates must be finite within -16384..16384.');
      }
    }
    if ($referenceY <= $horizonY || !is_finite($pixelsPerUnit) || $pixelsPerUnit <= 0 || $pixelsPerUnit > 16384) {
      throw new InvalidArgumentException('Ground scale needs a reference row below its horizon and positive pixels per unit within 0..16384.');
    }
    if (!is_finite(1 / ($referenceY - $horizonY))) {
      throw new InvalidArgumentException('Ground scale reference must resolve a finite projection.');
    }
  }

  /** Upright pixels per authored real-world unit at this ground row. */
  public function getPixelsPerUnitAt(float $footY): float
  {
    $pixels = $this->pixelsPerUnit * $this->getDepthScale($footY);
    if (!is_finite($pixels)) { throw new InvalidArgumentException('Measured pixels per unit must remain finite.'); }
    return $pixels;
  }

  /** Relative to the shared battler body reference at referenceY; no actor identity or body size changes. */
  public function getDepthScale(float $footY): float
  {
    if (!is_finite($footY) || $footY <= $this->horizonY) {
      throw new InvalidArgumentException('A calibrated ground row must lie below its horizon.');
    }
    $scale = ($footY - $this->horizonY) / ($this->referenceY - $this->horizonY);
    if (!is_finite($scale) || $scale <= 0) { throw new InvalidArgumentException('Measured ground scale must remain finite and positive.'); }
    return $scale;
  }

  /** The upright guide height for any authored real-world height, using the same projection as battlers. */
  public function getProjectedHeight(float $footY, float $height): float
  {
    if (!is_finite($height) || $height <= 0) {
      throw new InvalidArgumentException('A scale guide height must be finite and positive.');
    }
    $projected = $this->getPixelsPerUnitAt($footY) * $height;
    if (!is_finite($projected)) { throw new InvalidArgumentException('A projected scale guide must remain finite.'); }
    return $projected;
  }

  /** @return array{slope: float, intercept: float} scale(y) = slope*y + intercept. */
  public function getDepthScaleLine(): array
  {
    $slope = 1 / ($this->referenceY - $this->horizonY);
    return ['slope' => $slope, 'intercept' => -$this->horizonY * $slope];
  }

  /** @return array{horizonY: float, referenceY: float, pixelsPerUnit: float, vanishingX?: float} */
  public function toArray(): array
  {
    return ['horizonY' => $this->horizonY, 'referenceY' => $this->referenceY, 'pixelsPerUnit' => $this->pixelsPerUnit,
      ...($this->vanishingX === null ? [] : ['vanishingX' => $this->vanishingX])];
  }

  public static function fromArray(mixed $data, string $source = 'ground.scaleReference'): self
  {
    $required = ['horizonY', 'referenceY', 'pixelsPerUnit'];
    if (!is_array($data) || array_diff(array_keys($data), [...$required, 'vanishingX']) !== []
      || array_diff($required, array_keys($data)) !== []) {
      throw new InvalidArgumentException("{$source}: expected horizonY, referenceY, pixelsPerUnit and optional vanishingX.");
    }
    foreach ($data as $name => $value) {
      if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException("{$source}.{$name}: expected a finite number.");
      }
    }
    try {
      return new self($data['horizonY'], $data['referenceY'], $data['pixelsPerUnit'], $data['vanishingX'] ?? null);
    } catch (InvalidArgumentException $error) {
      throw new InvalidArgumentException("{$source}: {$error->getMessage()}", previous: $error);
    }
  }
}
