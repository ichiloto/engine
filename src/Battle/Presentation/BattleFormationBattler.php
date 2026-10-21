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
   * @param BattlerContact $contact What is drawn where it meets the surface.
   * @param list<CanvasImage> $contactImages That contact as art (a ripple or burrow sheet's frame).
   * @param list<CanvasComposite> $contactComposites That contact as the Engine draws it (a shadow, or a placeholder).
   * @param float $offset How far its body is raised above (positive) or sunk below (negative) its feet, in canvas units.
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
    public BattlerContact $contact = BattlerContact::NONE,
    public array $contactImages = [],
    public array $contactComposites = [],
    public float $offset = 0.0,
  ) {
    $this->ground = new Vector2($slot->x, $slot->y);
  }
}
