<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** A preview instance, not a live combatant or a second formation authority. */
final readonly class BattleFormationBattler
{
  public Vector2 $ground;

  /**
   * @param ?float $bodySpan Calibrated body axis in canvas units, not an inferred silhouette.
   * @param list<string> $diagnostics Optional-art failures retained for author feedback.
   * @param ?CanvasComposite $shadow Its contact shadow on the ground at its feet, when the Engine draws one.
   * @param float $lift How far its body hovers above its feet, in canvas units.
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
    public ?CanvasComposite $shadow = null,
    public float $lift = 0.0,
  ) {
    $this->ground = new Vector2($slot->x, $slot->y);
  }
}
