<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** A preview instance, not a live combatant or a second formation authority. */
final readonly class BattleFormationBattler
{
  public Vector2 $ground;

  /**
   * @param ?float $bodySpan Calibrated body axis in canvas units, not an inferred silhouette.
   * @param list<string> $diagnostics Optional-art failures retained for author feedback.
   */
  public function __construct(
    public string $id,
    public bool $party,
    public BattlerSlot $slot,
    public ?BattlerArtwork $artwork,
    public CanvasRectangle $bounds,
    public ?CanvasImage $image,
    public ?float $bodySpan,
    public bool $horizontal,
    public array $diagnostics = [],
  ) {
    $this->ground = new Vector2($slot->x, $slot->y);
  }
}
