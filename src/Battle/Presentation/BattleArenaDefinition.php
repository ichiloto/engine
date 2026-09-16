<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

final readonly class BattleArenaDefinition
{
  /** @var list<BattlerSlot> */
  public array $partySlots;
  /** @var list<BattlerSlot> */
  public array $enemySlots;
  public RendererGridConfig $uiGrid;

  /** @param list<BattlerSlot> $partySlots @param list<BattlerSlot> $enemySlots */
  public function __construct(
    public int $width,
    public int $height,
    public CanvasImage $background,
    array $partySlots,
    array $enemySlots,
    int $uiCellWidth = 10,
    int $uiCellHeight = 20,
    public ?BattleUiSkin $skin = null,
    public ?CanvasRectangle $feedbackArea = null,
  ) {
    new PresentationCanvas($width, $height, [$background]);
    $feedbackArea?->assertWithin($width, $height);
    if ($skin !== null && $feedbackArea === null) {
      throw new InvalidArgumentException('A skinned battle requires an explicit feedback safe area.');
    }
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
    $this->partySlots = self::slots($partySlots);
    $this->enemySlots = self::slots($enemySlots);
    $this->uiGrid = new RendererGridConfig(BattleScreen::WIDTH, BattleScreen::HEIGHT, $uiCellWidth, $uiCellHeight);
    if ($this->uiGrid->columns * $uiCellWidth > $width || $this->uiGrid->rows * $uiCellHeight > $height) {
      throw new InvalidArgumentException('The temporary battle UI grid must fit inside the graphical canvas.');
    }
  }

  /** @return list<BattlerSlot> */
  private static function slots(array $slots): array
  {
    if (!array_is_list($slots) || count($slots) > 64) {
      throw new InvalidArgumentException('Battle slots must be a list of at most 64 entries.');
    }
    $copy = [];
    foreach ($slots as $slot) {
      if (!$slot instanceof BattlerSlot) { throw new InvalidArgumentException('Battle slots must be typed BattlerSlot entries.'); }
      $copy[] = $slot;
    }
    return $copy;
  }
}
