<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\BattleTetherTrack;
use Ichiloto\Engine\Animations\Timelines\EffectImageDepth;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/** The live pose, target and current authored sample determine the weapon's drawn geometry. */
final class GraphicalBattleTether
{
  /** @return list<CanvasImage> */
  public static function compose(string $id, array $sample, BattleCanvasLayout $arena, string $root,
    array $bounds, array $ground, array $points, int $actor, int $target, bool $contact, bool $rotation, bool $clips): array
  {
    $targetBody = $bounds[$target] ?? throw new RuntimeException('Tether target has no drawn body.');
    $origin = $ground[$actor] ?? throw new RuntimeException('Tether caster has no ground anchor.');
    $grip = $points[$actor][$sample['grip']] ?? throw new RuntimeException('Current pose has no tether grip.');
    $targetCenter = new Vector2($targetBody->x + $targetBody->width / 2, $targetBody->y + $targetBody->height / 2);
    $facing = $targetCenter->x < $origin->x ? -1 : 1;
    $scale = $arena->height / PresentationCanvas::DEFAULT_HEIGHT;
    $basis = self::interpolate($origin, $targetCenter, $sample['targetWeight']);
    $head = new Vector2($basis->x + $sample['x'] * $scale * $facing, $basis->y + $sample['y'] * $scale);
    $guide = $grip;
    if ($sample['guideWeight'] > 0) {
      $guidePoint = $points[$actor][$sample['guide']] ?? throw new RuntimeException('Current pose has no tether guide.');
      $guide = self::interpolate($grip, $guidePoint, $sample['guideWeight']);
    }

    $headLayer = $sample['depth'] === EffectImageDepth::BEHIND->value ? 90 : 180;
    $linkLayer = $sample['linkDepth'] === EffectImageDepth::BEHIND->value ? 89 : 179;
    $headSize = PngAssetPreflight::inspect($root, $sample['head']);
    $headCrop = new SpriteSourceRect(0, 0, $headSize['width'], $headSize['height']);
    $pivot = PresentationSpritePivot::fromArray($sample['headPivot']);
    $headPivot = new PresentationSpritePivot($facing < 0 ? 1 - $pivot->x : $pivot->x, $pivot->y);
    $headAngle = $sample['rotation'] * $facing;
    $headHeight = $sample['headHeight'] * $scale;
    $clearance = $sample['clearance'] * $scale;
    $obstacles = array_diff_key($bounds, [$actor => true, $target => true]);
    $drawHead = static fn(Vector2 $point): ?CanvasImage => self::place($id . '-head', $sample['head'], $headCrop, $point,
      $headHeight, $headAngle, $headPivot, $arena, $headLayer, $rotation, $clips, $facing < 0);
    $headImage = $drawHead($head);
    if ($headImage === null) { throw new RuntimeException('Tether head is outside the battle canvas.'); }
    $paint = self::getHeadBounds($root, $headImage);
    $headObstacles = $contact ? $obstacles : [...$obstacles, $targetBody];
    $shift = self::getHeadClearanceShift($paint, $headObstacles, $clearance, $arena->height);
    if ($shift !== 0.0) {
      $head = new Vector2($head->x, $head->y + $shift);
      $headImage = $drawHead($head);
      if ($headImage === null) { throw new RuntimeException('Cleared tether head is outside the battle canvas.'); }
      $paint = self::getHeadBounds($root, $headImage);
    }
    if ($contact && !self::overlaps($paint, $targetBody)) {
      throw new RuntimeException('Tether head cannot reach the selected body at the impact sample.');
    }

    $first = self::interpolate($guide, $head, $sample['curveFirstProgress']);
    $second = self::interpolate($guide, $head, $sample['curveSecondProgress']);
    $first = new Vector2($first->x, $first->y - $sample['lift'] * $scale);
    $second = new Vector2($second->x, $second->y - $sample['lift'] * $scale);
    if ($sample['approach'] > 0) {
      $direction = deg2rad($facing < 0 ? 180 + $headAngle : $headAngle);
      $length = hypot($head->x - $guide->x, $head->y - $guide->y) * $sample['approach'];
      $second = new Vector2($head->x - cos($direction) * $length, $head->y - sin($direction) * $length);
    }

    $linkSize = PngAssetPreflight::inspect($root, $sample['links']);
    if ($linkSize['width'] % 2 !== 0) { throw new InvalidArgumentException('Tether links need two equal-width cells.'); }
    $linkWidth = intdiv($linkSize['width'], 2);
    $linkHeight = $sample['linkHeight'] * $scale;
    $radius = hypot($linkHeight, $linkHeight * $linkWidth / $linkSize['height']) / 2;
    $padding = $clearance + $radius;
    $main = BattleCubicFlightClearance::plan($guide, $head, $first, $second, $arena->height,
      $obstacles, $padding, width: $arena->width);
    if ($main === null) {
      throw new RuntimeException('No tether path clears the unselected battlers.');
    }
    $waypoints = $main->getWaypoints();
    $lead = null;
    if ($guide !== $grip) {
      $leadFirst = new Vector2($grip->x + $sample['guideGripOffset'] * $scale * $facing,
        $grip->y - $sample['guideGripLift'] * $scale);
      $leadSecond = new Vector2($guide->x + $sample['guideHandOffset'] * $scale * $facing,
        $guide->y - $sample['guideHandLift'] * $scale);
      $lead = BattleCubicFlightClearance::plan($grip, $guide, $leadFirst, $leadSecond,
        $arena->height, $obstacles, $padding, width: $arena->width);
      if ($lead === null) {
        throw new RuntimeException('No tether path clears the unselected battlers between the two pose points.');
      }
      $waypoints = [...$lead->getWaypoints(), ...array_slice($waypoints, 1)];
    }
    $path = new BattlePresentationPath(array_map(static fn(Vector2 $point): array => [$point->x, $point->y], $waypoints));
    $spacing = $sample['linkSpacing'] * $scale;
    $count = (int) floor($path->distance / $spacing) + 1;
    if ($count > BattleTetherTrack::MAX_LINKS) {
      throw new RuntimeException('Tether path exceeds the bounded link drawing limit.');
    }
    $images = [];
    $center = new PresentationSpritePivot(.5, .5);
    $crops = [new SpriteSourceRect(0, 0, $linkWidth, $linkSize['height']),
      new SpriteSourceRect($linkWidth, 0, $linkWidth, $linkSize['height'])];
    $leadLength = $lead?->distance ?? 0.0;
    $leadOcclusion = $lead?->occludedDepartureDistance ?? 0.0;
    $mainOcclusionEnd = $leadLength + $main->occludedDepartureDistance;
    for ($index = 0; $index < $count && $path->distance > 0; $index++) {
      $distance = $index * $spacing;
      $link = $path->getSampleAtProgress($distance / $path->distance);
      $angle = (int) round(rad2deg(atan2($link['direction']->y, $link['direction']->x)));
      $occluded = $distance < $leadOcclusion || ($distance >= $leadLength && $distance < $mainOcclusionEnd);
      $image = self::place($id . '-link-' . $index, $sample['links'], $crops[$index % 2], $link['point'],
        $linkHeight, $angle, $center, $arena, $occluded ? 15 : $linkLayer, $rotation, $clips);
      if ($image !== null) { $images[] = $image; }
    }
    return [...$images, $headImage];
  }

  private static function interpolate(Vector2 $start, Vector2 $end, float $fraction): Vector2
  {
    return new Vector2($start->x + ($end->x - $start->x) * $fraction,
      $start->y + ($end->y - $start->y) * $fraction);
  }

  private static function place(string $id, string $asset, SpriteSourceRect $crop, Vector2 $point, float $height,
    int $angle, PresentationSpritePivot $pivot, BattleCanvasLayout $arena, int $layer, bool $rotation, bool $clips, bool $flip = false): ?CanvasImage
  {
    $width = $height * $crop->width / $crop->height;
    $x = $point->x - $width * $pivot->x;
    $y = $point->y - $height * $pivot->y;
    if ($rotation) {
      $rotated = CanvasImage::createRotated($id, $asset, new CanvasRectangle($x, $y, $width, $height, allowOutsideCanvas: true),
        $arena->width, $arena->height, $layer, $crop, $flip, false, ($angle % 360 + 360) % 360, $pivot);
      if ($rotated === null || $rotated->clipRect === null || $clips) { return $rotated; }
    }
    return CanvasImage::createClipped($id, $asset, $x, $y, $width, $height, $arena->width, $arena->height, $layer, $crop, $flip);
  }

  private static function getHeadBounds(string $root, CanvasImage $image): CanvasRectangle
  {
    if (!function_exists('imagecreatefrompng')) { return $image->getPaintBounds(); }
    $visible = PngAssetBounds::getVisibleBounds($root, $image);
    return $visible['bounds'] ?? throw new RuntimeException($visible['diagnostic'] ?? 'Tether head has no visible pixels.');
  }

  private static function overlaps(CanvasRectangle $a, CanvasRectangle $b): bool
  {
    return $a->x < $b->x + $b->width && $a->x + $a->width > $b->x
      && $a->y < $b->y + $b->height && $a->y + $a->height > $b->y;
  }

  /** Choose the closest vertically clear position, without changing the authored frame or target. */
  private static function getHeadClearanceShift(CanvasRectangle $head, array $obstacles, float $padding, int $height): float
  {
    $intervals = [];
    foreach ($obstacles as $body) {
      if ($head->x + $head->width <= $body->x - $padding || $head->x >= $body->x + $body->width + $padding) { continue; }
      $intervals[] = [$body->y - $padding - $head->y - $head->height, $body->y + $body->height + $padding - $head->y];
    }
    $up = $down = 0.0;
    usort($intervals, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
    foreach ($intervals as [$first, $last]) { if ($first < $up && $up < $last) { $up = $first; } }
    usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    foreach ($intervals as [$first, $last]) { if ($first < $down && $down < $last) { $down = $last; } }
    $upFits = $head->y + $up >= 0;
    $downFits = $head->y + $head->height + $down <= $height;
    if (!$upFits && !$downFits) { throw new RuntimeException('No tether head position clears the unselected battlers.'); }
    return $upFits && (!$downFits || abs($up) <= $down) ? $up : $down;
  }
}
