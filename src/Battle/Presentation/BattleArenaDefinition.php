<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use InvalidArgumentException;

final readonly class BattleArenaDefinition extends BattleCanvasLayout
{
  /** @var list<BattlerSlot> */
  public array $partySlots;
  /** @var list<BattlerSlot> */
  public array $enemySlots;
  /** @var array<string, list<BattlerSlot>> Stable troop identities within this arena. */
  public array $enemySlotsByTroop;

  /**
   * @param list<BattlerSlot> $partySlots
   * @param list<BattlerSlot> $enemySlots
   * @param array<string, list<BattlerSlot>> $enemySlotsByTroop
   */
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
    array $enemySlotsByTroop = [],
  ) {
    parent::__construct($width, $height, $uiCellWidth, $uiCellHeight, $skin, $feedbackArea);
    new PresentationCanvas($width, $height, [$background]);
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
    $this->partySlots = self::getValidatedSlots($partySlots);
    $this->enemySlots = self::getValidatedSlots($enemySlots);
    $formations = [];
    foreach ($enemySlotsByTroop as $troopId => $slots) {
      if (!is_string($troopId) || !is_array($slots)) {
        throw new InvalidArgumentException('Troop formations require string identities and lists of BattlerSlot entries.');
      }
      CanvasValidation::id($troopId);
      $formations[$troopId] = self::getValidatedSlots($slots);
    }
    $this->enemySlotsByTroop = $formations;
  }

  public function getForTroop(string $troopId): self
  {
    if (!array_key_exists($troopId, $this->enemySlotsByTroop)) { return $this; }

    return new self($this->width, $this->height, $this->background,
      $this->partySlots, $this->enemySlotsByTroop[$troopId], $this->uiGrid->cellWidth, $this->uiGrid->cellHeight,
      $this->skin, $this->feedbackArea);
  }

  public function withDefaultUi(BattleCanvasLayout $ui): self
  {
    return $this->skin !== null ? $this : new self($this->width, $this->height, $this->background,
      $this->partySlots, $this->enemySlots, $this->uiGrid->cellWidth, $this->uiGrid->cellHeight,
      $ui->skin, $this->feedbackArea ?? $ui->feedbackArea, $this->enemySlotsByTroop);
  }

  /** @return list<BattlerSlot> */
  private static function getValidatedSlots(array $slots): array
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
