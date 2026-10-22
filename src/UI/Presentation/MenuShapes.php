<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use RuntimeException;

/**
 * Live vector shapes for a menu, drawn in theme colours into one bounded
 * composite: discs, rings (solid or dashed), rounded pills, lines and arcs,
 * and soft radial light. Callers give canvas coordinates; the composite owns
 * its area and clips everything to it, and shapes wholly outside it are
 * left out so a scrolled view costs only what it shows. No artwork, text or
 * input: those stay with MenuCanvas and the menu state.
 */
final class MenuShapes
{
  /** Points on each round end of a pill. */
  private const int PILL_ARC_POINTS = 8;
  /** Points per full turn of an arc or circle stroke. */
  private const int ARC_POINTS = 64;

  /** @var list<CanvasCompositeOperation> */
  private array $operations = [];

  public function __construct(private readonly MenuPresentationCatalog $theme, public readonly CanvasRectangle $bounds)
  {
    if ($bounds->width < 1 || $bounds->height < 1) {
      throw new RuntimeException('Menu shapes need an area to draw in.');
    }
  }

  /** How many shapes have been drawn so far. */
  public function countShapes(): int
  {
    return count($this->operations);
  }

  public function fillRectangle(CanvasRectangle $area, string|PresentationColor $color, float $opacity = 1.0): void
  {
    $this->fill([$area->x, $area->y, $area->width, $area->height], $color, $opacity);
  }

  public function fillCircle(float $x, float $y, float $radius, string|PresentationColor $color, float $opacity = 1.0): void
  {
    $this->fill(self::around($x, $y, $radius), $color, $opacity, [$this->createEllipseMask($x, $y, $radius)]);
  }

  /**
   * A disc filled up from its bottom to a share of its height, such as a node filling while Learn is held.
   */
  public function fillCircleFrom(float $x, float $y, float $radius, float $share, string|PresentationColor $color, float $opacity = 1.0): void
  {
    $share = min(1.0, max(0.0, $share));
    if ($share <= 0.0) {
      return;
    }
    $height = 2 * $radius * $share;
    $this->fill([$x - $radius, $y + $radius - $height, 2 * $radius, $height], $color, $opacity,
      [$this->createEllipseMask($x, $y, $radius)]);
  }

  /**
   * A ring of a width centred on a radius; dashed when given how many dashes go round it.
   */
  public function strokeCircle(float $x, float $y, float $radius, float $width, string|PresentationColor $color, float $opacity = 1.0,
    int $dashes = 0): void
  {
    $outer = $radius + $width / 2;
    $inner = max(0.5, $radius - $width / 2);
    $masks = [$this->createEllipseMask($x, $y, $outer), $this->createEllipseMask($x, $y, $inner, true)];
    if ($dashes > 0) {
      $masks[] = $this->createDashMask($x, $y, $inner - 2, $outer + 2, min(31, $dashes));
    }
    $this->fill(self::around($x, $y, $outer), $color, $opacity, $masks);
  }

  /** A pill (a rectangle with round ends) centred on a point. */
  public function fillPill(float $x, float $y, float $width, float $height, string|PresentationColor $color, float $opacity = 1.0): void
  {
    $area = [$x - $width / 2, $y - $height / 2, $width, $height];
    $this->fill($area, $color, $opacity, [$this->createPillMask($area)]);
  }

  /** A pill's outline of a width, inside its edge. */
  public function strokePill(float $x, float $y, float $width, float $height, float $lineWidth, string|PresentationColor $color,
    float $opacity = 1.0): void
  {
    $area = [$x - $width / 2, $y - $height / 2, $width, $height];
    $inside = [$area[0] + $lineWidth, $area[1] + $lineWidth, $width - 2 * $lineWidth, $height - 2 * $lineWidth];
    $this->fill($area, $color, $opacity, [$this->createPillMask($area), $this->createPillMask($inside, true)]);
  }

  /** A rectangle's outline of a width, inside its edge. */
  public function strokeRectangle(CanvasRectangle $area, float $lineWidth, string|PresentationColor $color, float $opacity = 1.0): void
  {
    foreach ([[$area->x, $area->y, $area->width, $lineWidth],
      [$area->x, $area->y + $area->height - $lineWidth, $area->width, $lineWidth],
      [$area->x, $area->y + $lineWidth, $lineWidth, $area->height - 2 * $lineWidth],
      [$area->x + $area->width - $lineWidth, $area->y + $lineWidth, $lineWidth, $area->height - 2 * $lineWidth]] as $edge) {
      $this->fill($edge, $color, $opacity);
    }
  }

  /**
   * A line through points, with round ends.
   *
   * @param list<array{float, float}> $points Canvas points, at least two.
   */
  public function strokeLine(array $points, float $width, string|PresentationColor $color, float $opacity = 1.0): void
  {
    $half = $width / 2;
    $xs = array_column($points, 0);
    $ys = array_column($points, 1);
    if (max($xs) + $half < $this->bounds->x || min($xs) - $half > $this->bounds->x + $this->bounds->width
      || max($ys) + $half < $this->bounds->y || min($ys) - $half > $this->bounds->y + $this->bounds->height) {
      return;
    }
    $local = array_map(fn(array $point): array => [$point[0] - $this->bounds->x, $point[1] - $this->bounds->y], $points);
    $this->add(['type' => 'stroke', 'points' => $local, 'width' => $width, 'brush' => $this->createBrush($color), 'opacity' => $opacity]);
  }

  /**
   * A dashed line between two points.
   */
  public function strokeDashedLine(float $x1, float $y1, float $x2, float $y2, float $width, float $dash, float $gap,
    string|PresentationColor $color, float $opacity = 1.0): void
  {
    $length = hypot($x2 - $x1, $y2 - $y1);
    if ($length <= 0) {
      return;
    }
    for ($at = 0.0; $at < $length; $at += $dash + $gap) {
      $end = min($length, $at + $dash);
      $this->strokeLine([[$x1 + ($x2 - $x1) * $at / $length, $y1 + ($y2 - $y1) * $at / $length],
        [$x1 + ($x2 - $x1) * $end / $length, $y1 + ($y2 - $y1) * $end / $length]], $width, $color, $opacity);
    }
  }

  /**
   * An arc clockwise from the top of a circle through a share of a full turn, such as a hold's progress.
   */
  public function strokeArc(float $x, float $y, float $radius, float $share, float $width, string|PresentationColor $color,
    float $opacity = 1.0): void
  {
    $share = min(1.0, max(0.0, $share));
    if ($share <= 0.0) {
      return;
    }
    $steps = max(2, (int)ceil(self::ARC_POINTS * $share));
    $points = [];
    for ($step = 0; $step <= $steps; $step++) {
      $angle = -M_PI / 2 + 2 * M_PI * $share * $step / $steps;
      $points[] = [$x + $radius * cos($angle), $y + $radius * sin($angle)];
    }
    $this->strokeLine($points, $width, $color, $opacity);
  }

  /**
   * Soft light falling off from a centre, such as the glow behind a board.
   */
  public function fillGlow(CanvasRectangle $area, float $x, float $y, float $radiusX, float $radiusY, string|PresentationColor $color,
    float $opacity): void
  {
    $center = [$x - $this->bounds->x, $y - $this->bounds->y];
    $this->fill([$area->x, $area->y, $area->width, $area->height], $color, 1.0, [], ['type' => 'radial', 'center' => $center, 'radius' => [$radiusX, $radiusY],
      'stops' => [['offset' => 0.0, 'color' => $this->resolveColor($color)->toArray(), 'opacity' => $opacity],
        ['offset' => 1.0, 'color' => $this->resolveColor($color)->toArray(), 'opacity' => 0.0]]]);
  }

  /** The composite of everything drawn, or null when nothing shows in its area. */
  public function compose(string $id, int $layer): ?CanvasComposite
  {
    if ($this->operations === []) {
      return null;
    }
    $width = (int)ceil($this->bounds->width);
    $height = (int)ceil($this->bounds->height);

    return new CanvasComposite($id, $width, $height, new CanvasRectangle($this->bounds->x, $this->bounds->y, $width, $height),
      $this->operations, $layer);
  }

  /**
   * @param array{float, float, float, float} $area Canvas x, y, width and height; it may reach past the composite.
   * @param list<array<string, mixed>> $masks
   */
  private function fill(array $area, string|PresentationColor $color, float $opacity, array $masks = [], ?array $brush = null): void
  {
    [$x, $y, $width, $height] = $area;
    $left = max($x, $this->bounds->x);
    $top = max($y, $this->bounds->y);
    $right = min($x + $width, $this->bounds->x + $this->bounds->width);
    $bottom = min($y + $height, $this->bounds->y + $this->bounds->height);
    if ($right - $left < 0.01 || $bottom - $top < 0.01 || $opacity <= 0.0) {
      return;
    }
    $this->add(['type' => 'fill', 'destination' => ['x' => $left - $this->bounds->x, 'y' => $top - $this->bounds->y,
      'width' => $right - $left, 'height' => $bottom - $top], 'brush' => $brush ?? $this->createBrush($color), 'opacity' => min(1.0, $opacity),
      'masks' => $masks]);
  }

  /** @param array<string, mixed> $data */
  private function add(array $data): void
  {
    if (count($this->operations) >= 256) {
      throw new RuntimeException('Menu shapes exceed a composite\'s 256 operations; terminal presentation retained.');
    }
    $this->operations[] = new CanvasCompositeOperation($data);
  }

  /** @return array<string, mixed> */
  private function createBrush(string|PresentationColor $color): array
  {
    return ['type' => 'solid', 'color' => $this->resolveColor($color)->toArray()];
  }

  private function resolveColor(string|PresentationColor $color): PresentationColor
  {
    return $color instanceof PresentationColor ? $color
      : ($this->theme->colors[$color] ?? throw new RuntimeException("Menu shapes need the theme colour {$color}."));
  }

  /** @return array<string, mixed> */
  private function createEllipseMask(float $x, float $y, float $radius, bool $invert = false): array
  {
    return ['type' => 'ellipse', 'center' => [$x - $this->bounds->x, $y - $this->bounds->y], 'radius' => [$radius, $radius], 'invert' => $invert];
  }

  /**
   * @param array{float, float, float, float} $area
   * @return array<string, mixed>
   */
  private function createPillMask(array $area, bool $invert = false): array
  {
    [$x, $y, $width, $height] = $area;
    $radius = min($height, $width) / 2;
    $left = $x + $radius - $this->bounds->x;
    $right = $x + $width - $radius - $this->bounds->x;
    $middle = $y + $height / 2 - $this->bounds->y;
    $points = [];
    foreach ([[$right, -M_PI / 2], [$left, M_PI / 2]] as [$x, $start]) {
      for ($step = 0; $step <= self::PILL_ARC_POINTS; $step++) {
        $angle = $start + M_PI * $step / self::PILL_ARC_POINTS;
        $points[] = [$x + $radius * cos($angle), $middle + $radius * sin($angle)];
      }
    }

    return ['type' => 'polygon', 'contours' => [$points], 'invert' => $invert];
  }

  /**
   * Teeth reaching across a ring, so a ring drawn through them shows only its dashes.
   *
   * @return array<string, mixed>
   */
  private function createDashMask(float $x, float $y, float $inner, float $outer, int $dashes): array
  {
    $points = [];
    $turn = 2 * M_PI / $dashes;
    for ($dash = 0; $dash < $dashes; $dash++) {
      $from = -M_PI / 2 + $dash * $turn;
      $to = $from + $turn / 2;
      foreach ([[$inner, $from], [$outer, $from], [$outer, $to], [$inner, $to]] as [$radius, $angle]) {
        $points[] = [$x - $this->bounds->x + $radius * cos($angle), $y - $this->bounds->y + $radius * sin($angle)];
      }
    }

    return ['type' => 'polygon', 'contours' => [$points]];
  }

  /** @return array{float, float, float, float} */
  private static function around(float $x, float $y, float $radius): array
  {
    return [$x - $radius, $y - $radius, 2 * $radius, 2 * $radius];
  }
}
