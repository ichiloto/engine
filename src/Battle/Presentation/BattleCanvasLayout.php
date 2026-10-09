<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Battle UI geometry is independent of optional arena and combatant artwork. */
readonly class BattleCanvasLayout
{
  public RendererGridConfig $uiGrid;
  /** @var list<BattlerSlot> */
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
    $this->partySlots = BattlerSlot::getValidatedList($partySlots);
    foreach ($this->partySlots as $slot) {
      if ($slot->x > $width || $slot->y > $height || $slot->width > $width || $slot->height > $height) {
        throw new InvalidArgumentException('Party slot geometry must fit inside the shared battle canvas.');
      }
    }
  }

  public function getForArena(BattleArenaDefinition $arena): self
  {
    return $arena->skin === null || $arena->skin === $this->skin ? $this
      : new self($this->width, $this->height, $this->uiGrid->cellWidth, $this->uiGrid->cellHeight,
        $arena->skin, $this->feedbackArea, $this->partySlots, $this->battlerArea, $this->enemyArea, $this->partyArea);
  }
}
