<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasGroupScale, CanvasImage, CanvasRectangle};
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** One authored camera sample; gameplay geometry remains in its neutral coordinate system. */
final readonly class BattleFieldTransform
{
  public function __construct(public float $x, public float $y, public float $scale = 1.0)
  {
    new CanvasGroupScale($scale, new PresentationSpritePivot(.5, .5));
    if (!is_finite($x) || !is_finite($y)) { throw new InvalidArgumentException('Battlefield offsets must be finite.'); }
  }

  public function createGroupScale(): ?CanvasGroupScale
  {
    return $this->scale === 1.0 ? null : new CanvasGroupScale($this->scale, new PresentationSpritePivot(.5, .5));
  }

  /** Require every viewport corner to map inside the child drawing and actual backdrop rectangle/clip.
   * Checking in inverse image space also handles a rotated backdrop without mistaking its bounding box for coverage.
   */
  public function assertCoverage(CanvasImage $background, int $width, int $height): void
  {
    $centreX = $width / 2; $centreY = $height / 2;
    $destination = $background->destination;
    $pivotX = $destination->x + ($background->pivot?->x ?? .5) * $destination->width;
    $pivotY = $destination->y + ($background->pivot?->y ?? .5) * $destination->height;
    $angle = deg2rad(-($background->rotation ?? 0));
    $field = new CanvasRectangle(0, 0, $width, $height);
    foreach ([[0, 0], [$width, 0], [$width, $height], [0, $height]] as [$x, $y]) {
      $localX = $centreX + ($x - $this->x - $centreX) / $this->scale;
      $localY = $centreY + ($y - $this->y - $centreY) / $this->scale;
      $imageX = $pivotX + ($localX - $pivotX) * cos($angle) - ($localY - $pivotY) * sin($angle);
      $imageY = $pivotY + ($localX - $pivotX) * sin($angle) + ($localY - $pivotY) * cos($angle);
      if (!self::containsPoint($field, $localX, $localY)
        || ($background->clipRect !== null && !self::containsPoint($background->clipRect, $localX, $localY))
        || !self::containsPoint($destination, $imageX, $imageY)) {
        throw new InvalidArgumentException('Authored battlefield transform leaves the viewport uncovered; provide explicit camera scale or scene coverage.');
      }
    }
  }

  private static function containsPoint(CanvasRectangle $rectangle, float $x, float $y): bool
  {
    // Floating-point arithmetic tolerance only; it cannot conceal a subpixel authored gap.
    return $x >= $rectangle->x - 1e-9 && $y >= $rectangle->y - 1e-9
      && $x <= $rectangle->x + $rectangle->width + 1e-9 && $y <= $rectangle->y + $rectangle->height + 1e-9;
  }
}
