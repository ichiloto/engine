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

  public function __construct(
    public int $width,
    public int $height,
    int $uiCellWidth = 10,
    int $uiCellHeight = 20,
    public ?BattleUiSkin $skin = null,
    public ?CanvasRectangle $feedbackArea = null,
  ) {
    new PresentationCanvas($width, $height);
    $feedbackArea?->assertWithin($width, $height);
    if ($skin !== null && $feedbackArea === null) {
      throw new InvalidArgumentException('A skinned battle requires an explicit feedback safe area.');
    }
    $this->uiGrid = new RendererGridConfig(BattleScreen::WIDTH, BattleScreen::HEIGHT, $uiCellWidth, $uiCellHeight);
    if ($this->uiGrid->columns * $uiCellWidth > $width || $this->uiGrid->rows * $uiCellHeight > $height) {
      throw new InvalidArgumentException('The battle UI grid must fit inside the graphical canvas.');
    }
  }
}
