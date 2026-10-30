<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/**
 * Battle UI geometry is independent of optional arena and combatant artwork.
 * The theme also owns where battlers stand: its party formation (stage slots
 * from back to front), the default ground for arenas that declare none, the
 * gap between the ground's near edge and the battle UI, and the spacing the
 * Engine arranges unplaced battlers with ({@see BattleStaging}). It also owns
 * the contact shadow drawn under battlers' feet ({@see BattlerShadowStyle};
 * none draws none), the ripple and burrow drawn where a sunk battler breaks
 * the surface ({@see BattleContactEffects}), and how high air enemies rise by
 * default, in reference heights ({@see BattlerGroundContact}).
 */
readonly class BattleCanvasLayout
{
  /** The battle UI's bottom windows span the compatibility grid's last six rows. */
  public const int HUD_ROWS = 6;
  public RendererGridConfig $uiGrid;
  /** @var list<BattleStageSlot> Back to front; a smaller party takes the front-most. */
  public array $partySlots;

  public function __construct(
    public int $width,
    public int $height,
    int $uiCellWidth = 10,
    int $uiCellHeight = 20,
    public ?BattleUiSkin $skin = null,
    public ?CanvasRectangle $feedbackArea = null,
    array $partySlots = [],
    public ?CanvasRectangle $battlerArea = null,
    public ?CanvasRectangle $enemyArea = null,
    public ?CanvasRectangle $partyArea = null,
    public ?BattleGround $ground = null,
    public float $groundGap = 0,
    public BattleFormationSpacing $spacing = new BattleFormationSpacing(),
    public ?BattlerShadowStyle $shadow = null,
    public float $airElevation = 0.0,
    public BattleContactEffects $contactEffects = new BattleContactEffects(),
  ) {
    new PresentationCanvas($width, $height);
    $feedbackArea?->assertWithin($width, $height);
    $battlerArea?->assertWithin($width, $height);
    $enemyArea?->assertWithin($width, $height);
    $partyArea?->assertWithin($width, $height);
    if ($skin !== null && $feedbackArea === null) {
      throw new InvalidArgumentException('A skinned battle requires an explicit feedback safe area.');
    }
    $this->uiGrid = new RendererGridConfig(BattleScreen::WIDTH, BattleScreen::HEIGHT, $uiCellWidth, $uiCellHeight);
    if ($this->uiGrid->columns * $uiCellWidth > $width || $this->uiGrid->rows * $uiCellHeight > $height) {
      throw new InvalidArgumentException('The battle UI grid must fit inside the graphical canvas.');
    }
    $this->partySlots = BattleStageSlot::getValidatedList($partySlots);
    foreach ($this->partySlots as $slot) {
      if ($slot->x > $width || $slot->depth > $height || $slot->width > $width || $slot->height > $height) {
        throw new InvalidArgumentException('Party slot geometry must fit inside the shared battle canvas.');
      }
    }
    if (!is_finite($airElevation) || $airElevation < 0 || $airElevation > 4) {
      throw new InvalidArgumentException('Fliers rise between 0 and 4 reference heights.');
    }
    if (!is_finite($groundGap) || $groundGap < 0 || $groundGap >= $this->getHudTop()) {
      throw new InvalidArgumentException('The ground gap must be finite and leave ground above the battle UI.');
    }
    $this->getGround(null);
  }

  /** The canvas height where the battle UI's bottom windows begin, the same rows GraphicalBattleHud draws. */
  public function getHudTop(): float
  {
    return ($this->height - BattleScreen::HEIGHT * $this->uiGrid->cellHeight) / 2
      + (BattleScreen::HEIGHT - self::HUD_ROWS) * $this->uiGrid->cellHeight;
  }

  /**
   * The ground an arena's battlers stand on: what the arena declares, over the
   * theme's ground, over the defaults. The far edge defaults to the top of
   * the battler area (or the canvas) and the near edge to the battle UI's top
   * less the theme's gap; perspective defaults to none.
   */
  public function getGround(?BattleArenaDefinition $arena): BattleGroundBand
  {
    $ground = $arena?->ground?->getOver($this->ground) ?? $this->ground ?? new BattleGround();
    $top = $ground->top ?? $this->battlerArea?->y ?? 0;
    $bottom = $ground->bottom ?? $this->getHudTop() - $this->groundGap;
    if ($top >= $bottom || $bottom > $this->height) {
      throw new InvalidArgumentException(sprintf('Battle ground from %s to %s must run down the canvas above its bottom edge%s.',
        $top, $bottom, $arena === null ? '' : " for arena {$arena->name}"));
    }
    return new BattleGroundBand($top, $bottom, $ground->perspective ?? 1.0, $ground->scaleReference);
  }

  public function getForArena(BattleArenaDefinition $arena): self
  {
    return $arena->skin === null || $arena->skin === $this->skin ? $this
      : new self($this->width, $this->height, $this->uiGrid->cellWidth, $this->uiGrid->cellHeight,
        $arena->skin, $this->feedbackArea, $this->partySlots, $this->battlerArea, $this->enemyArea, $this->partyArea,
        $this->ground, $this->groundGap, $this->spacing, $this->shadow, $this->airElevation,
        $this->contactEffects);
  }
}
