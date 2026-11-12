<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use InvalidArgumentException;

/** A presentation snapshot; visible overhang and occupied ground have independent extents. */
final readonly class BattleTravelSubject
{
  public float $footX;
  public float $footY;

  /**
   * All values are resolved canvas units. The adapter supplies the existing
   * BattleFormationClearance::getFooting() result; this value never derives a
   * second ground footprint from the image, pose height or combat formation.
   */
  public function __construct(Vector2 $foot, public CanvasRectangle $body, public CanvasRectangle $footing)
  {
    if (!is_finite($foot->x) || !is_finite($foot->y)) {
      throw new InvalidArgumentException('Battle travel foot anchors must be finite.');
    }
    $this->footX = $foot->x;
    $this->footY = $foot->y;
  }
}
