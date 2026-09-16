<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use InvalidArgumentException;

final readonly class BattleArenaDefinition extends BattleCanvasLayout
{
  /** @var list<BattlerSlot> */
  public array $partySlots;
  /** @var list<BattlerSlot> */
  public array $enemySlots;

  /** @param list<BattlerSlot> $partySlots @param list<BattlerSlot> $enemySlots */
  public function __construct(
    int $width,
    int $height,
    public CanvasImage $background,
    array $partySlots,
    array $enemySlots,
    int $uiCellWidth = 10,
    int $uiCellHeight = 20,
    ?BattleUiSkin $skin = null,
    ?CanvasRectangle $feedbackArea = null,
  ) {
    parent::__construct($width, $height, $uiCellWidth, $uiCellHeight, $skin, $feedbackArea);
    new PresentationCanvas($width, $height, [$background]);
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
    $this->partySlots = self::slots($partySlots);
    $this->enemySlots = self::slots($enemySlots);
  }

  public function withDefaultUi(BattleCanvasLayout $ui): self
  {
    return $this->skin !== null ? $this : new self($this->width, $this->height, $this->background,
      $this->partySlots, $this->enemySlots, $this->uiGrid->cellWidth, $this->uiGrid->cellHeight,
      $ui->skin, $this->feedbackArea ?? $ui->feedbackArea);
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
