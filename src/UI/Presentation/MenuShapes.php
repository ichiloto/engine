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
 * composite: discs, rings (solid or dashed), rounded pills, lines (solid or
 * dashed) and arcs, and soft radial light. Callers give canvas coordinates;
 * the composite owns its area and clips everything to it, and shapes wholly
 * outside it are left out so a scrolled view costs only what it shows. Every
 * shape is one operation of the frame's shared budget, however long: a
 * dashed line is one stroke the renderer dashes where it negotiated
 * canvas_stroke_dashes, else one stroke per straight piece, masked to its
 * dashes. No artwork, text or input: those stay with MenuCanvas and the menu
 * state.
 */
final class MenuShapes
{
  /** Points on each round end of a pill. */
  private const int PILL_ARC_POINTS = 8;
  /** Points per full turn of an arc or circle stroke. */
  private const int ARC_POINTS = 64;
  /** Dashes one mask contour holds (four points each) and contours one mask holds, for a renderer without stroke dashes. */
  private const int MASK_DASHES = 32;
  private const int MASK_CONTOURS = 8;
  /** The most raster work one masked dash piece may ask for: pixels it covers times its mask's points. */
  private const int MASK_DASH_WORK = 4194304;

  /** @var list<CanvasCompositeOperation> */
  private array $operations = [];

  /**
   * @param bool $strokeDashes Whether the renderer negotiated canvas_stroke_dashes and so dashes a stroke itself.
   */
  public function __construct(private readonly MenuPresentationCatalog $theme, public readonly CanvasRectangle $bounds,
    private readonly bool $strokeDashes = false)
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
   *
   * @param list<CanvasRectangle> $clear Rounded areas (pills; a square one is a disc) the ring parts around, at most four.
   */
  public function strokeCircle(float $x, float $y, float $radius, float $width, string|PresentationColor $color, float $opacity = 1.0,
    int $dashes = 0, array $clear = []): void
  {
    $outer = $radius + $width / 2;
    $inner = max(0.5, $radius - $width / 2);
    $masks = [$this->createEllipseMask($x, $y, $outer), $this->createEllipseMask($x, $y, $inner, true)];
    if ($dashes > 0) {
      $masks[] = $this->createDashMask($x, $y, $inner - 2, $outer + 2, min(31, $dashes));
    }
    $this->fill(self::around($x, $y, $outer), $color, $opacity, [...$masks, ...$this->createClearMasks($clear)]);
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
   * @param list<CanvasRectangle> $clear Rounded areas the line parts around, at most four.
   */
  public function strokeLine(array $points, float $width, string|PresentationColor $color, float $opacity = 1.0, array $clear = []): void
  {
    $this->addStroke($points, $width, $color, $opacity, $this->createClearMasks($clear));
  }

  /**
   * A dashed line through points: dashes of a length with gaps between, measured along the whole line so its
   * corners keep the rhythm, and always one operation of the frame's budget per straight piece at most.
   *
   * @param list<array{float, float}> $points Canvas points, at least two.
   */
  public function strokeDashedLine(array $points, float $width, float $dash, float $gap, string|PresentationColor $color,
    float $opacity = 1.0): void
  {
    if ($dash <= 0 || $gap <= 0) {
      throw new RuntimeException('Dashed menu lines need positive dashes and gaps.');
    }
    if ($this->strokeDashes) {
      $this->addStroke($points, $width, $color, $opacity, [], ['length' => $dash, 'gap' => $gap]);
      return;
    }
    // Without the renderer's dashes each straight piece is one stroke masked to its dashes, the rhythm running on
    // across corners from where the piece before left it.
    $along = 0.0;
    for ($piece = 0; $piece < count($points) - 1; $piece++) {
      [$from, $to] = [$points[$piece], $points[$piece + 1]];
      $length = hypot($to[0] - $from[0], $to[1] - $from[1]);
      if ($length > 0) {
        $mask = $this->createLineDashMask($from, $to, $length, $width, $dash, $gap, $along, $piece === 0, $piece === count($points) - 2);
        if ($mask !== null) {
          $this->addStroke([$from, $to], $width, $color, $opacity, [$mask]);
        }
      }
      $along += $length;
    }
  }

  /**
   * An arc clockwise from the top of a circle through a share of a full turn, such as a hold's progress.
   *
   * @param list<CanvasRectangle> $clear Rounded areas the arc parts around, at most four.
   */
  public function strokeArc(float $x, float $y, float $radius, float $share, float $width, string|PresentationColor $color,
    float $opacity = 1.0, array $clear = []): void
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
    $this->strokeLine($points, $width, $color, $opacity, $clear);
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

  /**
   * @param list<array{float, float}> $points Canvas points, at least two.
   * @param list<array<string, mixed>> $masks
   * @param array{length: float, gap: float}|null $dash
   */
  private function addStroke(array $points, float $width, string|PresentationColor $color, float $opacity, array $masks = [],
    ?array $dash = null): void
  {
    $half = $width / 2;
    $xs = array_column($points, 0);
    $ys = array_column($points, 1);
    if (max($xs) + $half < $this->bounds->x || min($xs) - $half > $this->bounds->x + $this->bounds->width
      || max($ys) + $half < $this->bounds->y || min($ys) - $half > $this->bounds->y + $this->bounds->height) {
      return;
    }
    $local = array_map(fn(array $point): array => [$point[0] - $this->bounds->x, $point[1] - $this->bounds->y], $points);
    $this->add(['type' => 'stroke', 'points' => $local, 'width' => $width, 'brush' => $this->createBrush($color), 'opacity' => $opacity,
      ...($masks === [] ? [] : ['masks' => $masks]), ...($dash === null ? [] : ['dash' => $dash])]);
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
    // A pill as tall as it is wide is a disc: its two ends meet, so their shared points are given once.
    $round = $right - $left < 0.01;
    foreach ([[$right, -M_PI / 2], [$left, M_PI / 2]] as [$x, $start]) {
      for ($step = 0; $step <= self::PILL_ARC_POINTS - ($round ? 1 : 0); $step++) {
        $angle = $start + M_PI * $step / self::PILL_ARC_POINTS;
        $points[] = [$x + $radius * cos($angle), $middle + $radius * sin($angle)];
      }
    }

    return ['type' => 'polygon', 'contours' => [$points], 'invert' => $invert];
  }

  /**
   * @param list<CanvasRectangle> $areas
   * @return list<array<string, mixed>>
   */
  private function createClearMasks(array $areas): array
  {
    if (count($areas) > 4) {
      throw new RuntimeException('A menu stroke parts around at most four areas.');
    }

    return array_map(fn(CanvasRectangle $area): array => $this->createPillMask([$area->x, $area->y, $area->width, $area->height], true), $areas);
  }

  /**
   * Teeth reaching across one straight piece of a dashed line, so a stroke drawn through them shows only its dashes:
   * one tooth per dash, butt-ended, a stroke's round end kept only where the whole line starts or ends on a dash and
   * where a dash runs on round a corner. A piece with more dashes than a mask holds, or whose teeth would cost more than
   * MASK_DASH_WORK, takes longer dashes and gaps in the same proportion. Null when no dash falls on the piece.
   *
   * @param array{float, float} $from
   * @param array{float, float} $to
   * @param float $along How far along the whole line the piece starts.
   * @return array<string, mixed>|null
   */
  private function createLineDashMask(array $from, array $to, float $length, float $width, float $dash, float $gap, float $along,
    bool $first, bool $last): ?array
  {
    $half = $width / 2;
    $box = (abs($to[0] - $from[0]) + $width + 2) * (abs($to[1] - $from[1]) + $width + 2);
    $limit = min(self::MASK_DASHES * self::MASK_CONTOURS, max(1, intdiv((int)(self::MASK_DASH_WORK / $box), 4)));
    $scale = max(1.0, ceil(($length / ($dash + $gap) + 2) / $limit));
    [$dash, $gap] = [$dash * $scale, $gap * $scale];
    $period = $dash + $gap;
    $teeth = [];
    for ($start = floor($along / $period) * $period - $along; $start < $length; $start += $period) {
      $end = $start + $dash;
      if ($end <= 0) {
        continue;
      }
      // A dash running on past either end of the piece keeps the stroke's round join there; the line's own ends stay butt.
      $teeth[] = [$start <= 0 && ! $first ? -$half - 1 : max(0.0, $start), $end >= $length && ! $last ? $length + $half + 1 : min($length, $end)];
    }
    if ($teeth === []) {
      return null;
    }
    [$ux, $uy] = [($to[0] - $from[0]) / $length, ($to[1] - $from[1]) / $length];
    $reach = $half + 2;
    $point = fn(float $at, float $side): array => [$from[0] + $ux * $at - $uy * $side - $this->bounds->x,
      $from[1] + $uy * $at + $ux * $side - $this->bounds->y];
    $contours = [];
    foreach (array_chunk($teeth, self::MASK_DASHES) as $chunk) {
      $contour = [];
      foreach ($chunk as [$start, $end]) {
        array_push($contour, $point($start, -$reach), $point($start, $reach), $point($end, $reach), $point($end, -$reach));
      }
      $contours[] = $contour;
    }

    return ['type' => 'polygon', 'contours' => $contours];
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
