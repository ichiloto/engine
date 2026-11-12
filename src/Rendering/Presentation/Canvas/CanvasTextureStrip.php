<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Source crop mapped to a bent strip with a single perpendicular thickness. */
final readonly class CanvasTextureStrip
{
  public const int MAX_KNOTS = 32;

  /** @var array{float, float} */
  public array $origin;
  /** @var array{float, float} */
  public array $axis;
  public float $length;
  public float $height;
  /** @var list<array{u: float, offset: float}> */
  public array $knots;

  public function __construct(array $geometry)
  {
    CanvasCompositeValues::validateKeys($geometry, ['origin', 'axis', 'length', 'height', 'knots']);
    $this->origin = CanvasCompositeValues::getPoint($geometry['origin']);
    $this->axis = CanvasCompositeValues::getPoint($geometry['axis'], -1, 1);
    if (abs(hypot(...$this->axis) - 1) > 1e-9) {
      throw new InvalidArgumentException('Canvas texture strip axis must have unit length.');
    }
    $this->length = CanvasCompositeValues::getNumber($geometry['length'], 1 / 1024, 16384);
    $this->height = CanvasCompositeValues::getNumber($geometry['height'], 1 / 1024, 16384);
    $knots = [];
    $previous = -1.0;
    foreach (CanvasCompositeValues::getList($geometry['knots'], 2, self::MAX_KNOTS) as $knot) {
      CanvasCompositeValues::validateKeys($knot, ['u', 'offset']);
      $u = CanvasCompositeValues::getNumber($knot['u'], 0, 1);
      if ($u <= $previous) {
        throw new InvalidArgumentException('Canvas texture strip knots must strictly increase in source U.');
      }
      $knots[] = ['u' => $u, 'offset' => CanvasCompositeValues::getNumber($knot['offset'], -16384, 16384)];
      $previous = $u;
    }
    if ($knots[0]['u'] !== 0.0 || $previous !== 1.0) {
      throw new InvalidArgumentException('Canvas texture strip knots must cover the full source width.');
    }
    $this->knots = $knots;
  }

  /** @return array{float, float, float, float} Conservative destination bounds, prior to clipping. */
  public function getBounds(): array
  {
    [$x, $y] = $this->origin;
    [$cos, $sin] = $this->axis;
    $offsets = array_column($this->knots, 'offset');
    $top = min($offsets);
    $bottom = max($offsets) + $this->height;
    // The rail stays between its adjacent offsets. Bound its full oriented envelope.
    $left = $upper = INF;
    $right = $lower = -INF;
    foreach ([0.0, $this->length] as $along) {
      foreach ([$top, $bottom] as $across) {
        $px = $x + $along * $cos - $across * $sin;
        $py = $y + $along * $sin + $across * $cos;
        $left = min($left, $px);
        $right = max($right, $px);
        $upper = min($upper, $py);
        $lower = max($lower, $py);
      }
    }
    return [$left, $upper, $right, $lower];
  }

  /** @return ?array{float, float} Half-open normalized coordinates at one destination pixel centre. */
  public function getSourcePoint(float $x, float $y): ?array
  {
    $dx = $x - $this->origin[0];
    $dy = $y - $this->origin[1];
    [$cos, $sin] = $this->axis;
    $u = ($dx * $cos + $dy * $sin) / $this->length;
    if ($u < 0 || $u >= 1) { return null; }
    $low = 0;
    $high = count($this->knots) - 1;
    while ($high - $low > 1) {
      $middle = intdiv($low + $high, 2);
      if ($u < $this->knots[$middle]['u']) { $high = $middle; } else { $low = $middle; }
    }
    $start = $this->knots[$low];
    $end = $this->knots[$high];
    $t = ($u - $start['u']) / ($end['u'] - $start['u']);
    $offset = $start['offset'] + ($end['offset'] - $start['offset']) * $t * $t * (3 - 2 * $t);
    $v = (-$dx * $sin + $dy * $cos - $offset) / $this->height;
    return $v < 0 || $v >= 1 ? null : [$u, $v];
  }

  public function toArray(): array
  {
    return ['origin' => $this->origin, 'axis' => $this->axis, 'length' => $this->length,
      'height' => $this->height, 'knots' => $this->knots];
  }
}
