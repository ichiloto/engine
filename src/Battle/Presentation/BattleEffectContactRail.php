<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureStrip;
use InvalidArgumentException;

/** Fits a single image strip to the lower torso of a captured group. */
final readonly class BattleEffectContactRail
{
  /**
   * @param array{float, float} $origin Captured ground origin in logical pixels.
   * @param array{float, float} $axis Unit vector in the direction of increasing source X.
   * @param list<array{float, float}> $knots Along/across coordinates of the fitted contact points.
   */
  private function __construct(
    public array $origin,
    public array $axis,
    public array $knots,
    public float $center,
    public float $length,
    public float $height,
  ) {}

  public static function fit(BattleEffectTargetGroup $group): self
  {
    $origin = $group->getGroundOrigin();
    $contacts = $heights = $widths = [];
    foreach ($group->memberBounds as $id => $box) {
      $ground = $group->grounds[$id];
      $contacts[] = [
        $box->x + $box->width / 2 - $origin->x,
        min($box->y + .59 * $box->height, $ground->y - .21 * $box->height) - $origin->y,
      ];
      $heights[] = $box->height;
      $widths[] = $box->width;
    }
    sort($heights);
    $medianHeight = $heights[intdiv(count($heights), 2)];
    $axis = self::fitAxis($contacts, $medianHeight);
    $projected = self::projectContacts($contacts, $axis);

    $knots = [];
    $count = 0;
    foreach ($projected as [$along, $across]) {
      $index = array_key_last($knots);
      if ($index !== null && $along - $knots[$index][0] < 1) {
        $knots[$index][1] += ($across - $knots[$index][1]) / ++$count;
      } else {
        $knots[] = [$along, $across];
        $count = 1;
      }
    }

    $first = $projected[0][0];
    $last = $projected[array_key_last($projected)][0];
    $across = array_column($projected, 1);
    $length = max(72.0, $last - $first + 2 * max(10, .42 * $medianHeight), .94 * max($widths));
    $height = min(104.0, max(34, .86 * $medianHeight, .16 * $length, .3 * (max($across) - min($across))));
    return new self([$origin->x, $origin->y], $axis, $knots, ($first + $last) / 2, $length, $height);
  }

  /**
   * Use the principal axis, then penalize steep bends that would squeeze
   * separate contacts into a narrow band of source pixels.
   * @param list<array{float, float}> $points
   * @return array{float, float}
   */
  private static function fitAxis(array $points, float $medianHeight): array
  {
    $count = count($points);
    $centerX = array_sum(array_column($points, 0)) / $count;
    $centerY = array_sum(array_column($points, 1)) / $count;
    $xx = $yy = $xy = 0.0;
    foreach ($points as [$x, $y]) {
      $dx = $x - $centerX;
      $dy = $y - $centerY;
      $xx += $dx * $dx;
      $yy += $dy * $dy;
      $xy += $dx * $dy;
    }
    $angle = .5 * atan2(2 * $xy, $xx - $yy);
    $bodyGap = max(6, .18 * $medianHeight);
    $bestScore = INF;
    $best = [1.0, 0.0];
    foreach ([0, -12, 12, -24, 24, -36, 36, -48, 48, -60, 60, -72, 72] as $degrees) {
      $candidate = $angle + deg2rad($degrees);
      $cos = cos($candidate);
      $sin = sin($candidate);
      $axis = $cos < 0 ? [-$cos, -$sin] : [$cos, $sin];
      $projected = self::projectContacts($points, $axis);
      $slope = 0.0;
      for ($i = 1; $i < $count; $i++) {
        $dx = $projected[$i][0] - $projected[$i - 1][0];
        $dy = $projected[$i][1] - $projected[$i - 1][1];
        if (hypot($dx, $dy) > $bodyGap) {
          $slope = max($slope, abs($dy) / max(1.0, $dx));
        }
      }
      $score = $slope + .008 * abs($degrees);
      if ($score < $bestScore) {
        $bestScore = $score;
        $best = $axis;
      }
    }
    return $best;
  }

  /** @param list<array{float, float}> $points @param array{float, float} $axis
   * @return list<array{float, float}>
   */
  private static function projectContacts(array $points, array $axis): array
  {
    [$cos, $sin] = $axis;
    $projected = array_map(static fn(array $point): array => [
      $point[0] * $cos + $point[1] * $sin,
      -$point[0] * $sin + $point[1] * $cos,
    ], $points);
    sort($projected, SORT_REGULAR);
    return $projected;
  }

  public function getHeightAt(float $along): float
  {
    [$previousX, $previousY] = $this->knots[0];
    if ($along <= $previousX) { return $previousY; }
    foreach (array_slice($this->knots, 1) as [$x, $y]) {
      if ($along <= $x) {
        $t = ($along - $previousX) / ($x - $previousX);
        return $previousY + ($y - $previousY) * $t * $t * (3 - 2 * $t);
      }
      [$previousX, $previousY] = [$x, $y];
    }
    return $previousY;
  }

  /** @return array{left: float, right: float, ridgeY: float, referenceHeight: float} */
  public static function parseSource(mixed $source): array
  {
    $fields = ['left', 'right', 'ridgeY', 'referenceHeight'];
    CanvasCompositeValues::validateKeys($source, $fields);
    $values = [];
    foreach ($fields as $field) {
      $values[$field] = CanvasCompositeValues::getNumber($source[$field], 0, 1);
    }
    if ($values['right'] <= $values['left'] || $values['referenceHeight'] <= 0) {
      throw new InvalidArgumentException('Contact rail requires a positive source ridge span and reference height.');
    }
    return $values;
  }

  /**
   * The authored ridge spans from left to right at ridgeY. Height is fitted
   * per referenceHeight of the source. All source measurements use 0..1.
   *
   * @param array{left: float, right: float, ridgeY: float, referenceHeight: float} $source
   */
  public function createStrip(array $source): CanvasTextureStrip
  {
    ['left' => $left, 'right' => $right, 'ridgeY' => $ridge, 'referenceHeight' => $reference] = self::parseSource($source);
    $length = $this->length / ($right - $left);
    $start = $this->center - ($left + $right) * $length / 2;
    $height = $this->height / $reference;
    $knots = [['u' => 0.0, 'offset' => $this->getHeightAt($start) - $ridge * $height]];
    foreach ($this->knots as [$along, $across]) {
      $u = ($along - $start) / $length;
      if ($u > 0 && $u < 1) { $knots[] = ['u' => $u, 'offset' => $across - $ridge * $height]; }
    }
    $knots[] = ['u' => 1.0, 'offset' => $this->getHeightAt($start + $length) - $ridge * $height];
    [$cos, $sin] = $this->axis;
    return new CanvasTextureStrip(['origin' => [$this->origin[0] + $start * $cos, $this->origin[1] + $start * $sin],
      'axis' => $this->axis, 'length' => $length, 'height' => $height, 'knots' => $knots]);
  }
}
