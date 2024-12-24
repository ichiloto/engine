<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes;

use Exception;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use RuntimeException;

/**
 * Represents the equipment selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes
 */
class EquipmentSelectionMode extends EquipmentMenuMode implements CanRender
{
  /**
   * @var Character|null The character.
   */
  public ?Character $character = null;
  /**
   * @var EquipmentSlot|null The equipment slot.
   */
  public ?EquipmentSlot $equipmentSlot = null;
  /**
   * @var EquipmentSlotSelectionMode|null The previous mode.
   */
  public ?EquipmentSlotSelectionMode $previousMode = null;
  /**
   * @var Equipment[] The compatible equipment.
   */
  protected array $compatibleEquipment = [];
  /**
   * @var int The active index.
   */
  protected int $activeIndex = 0;
  /**
   * @var Equipment|null The active equipment.
   */
  protected ?Equipment $activeEquipment {
    get {
      return $this->compatibleEquipment[$this->activeIndex] ?? null;
    }
  }
  /**
   * @var int The total equipment.
   */
  protected int $totalEquipment = 0;

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the player.
   */
  public function update(): void
  {
    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->activeIndex = 0;
    $this->compatibleEquipment = $this->getCompatibleEquipment(
      $this->state->getGameScene()->party->inventory,
      $this->equipmentSlot->acceptsType ?? throw new RuntimeException('Equipment selection slot cannot be null.')
    );
    $this->updateCharacterDetailPanel();
    if ($this->activeEquipment) {
      $this->state->equipmentInfoPanel->setText($this->activeEquipment->description ?? '');
    }
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->activeIndex = -1;
    $this->state->characterDetailPanel->setDetails($this->character);
    $this->state->equipmentInfoPanel->setText('');
    $this->erase();
  }

  /**
   * Returns a list of compatible equipment from the inventory.
   *
   * @template T
   * @param Inventory $inventory The inventory.
   * @param class-string<T> $equipmentType The equipment type.
   * @return T[] The compatible equipment.
   */
  public function getCompatibleEquipment(Inventory $inventory, string $equipmentType): array
  {
    $compatibleEquipment = array_filter($inventory->equipment->toArray(), fn(Equipment $equipment) => is_a($equipment, $equipmentType));
    $this->totalEquipment = count($compatibleEquipment);

    return $compatibleEquipment;
  }

  /**
   * Selects the previous equipment.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $this->activeIndex = wrap($this->activeIndex - 1, 0, $this->totalEquipment - 1);
  }

  /**
   * Selects the next equipment.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $this->activeIndex = wrap($this->activeIndex + 1, 0, $this->totalEquipment - 1);
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $contentHeight = $this->state->equipmentAssignmentPanel->getSize()->height ?? new RuntimeException('Equipment assignment panel height is not set.');
    $contentHeight -= 2; // Subtract 2 for the top and bottom borders.
    $content = array_fill(0, $contentHeight, '');

    foreach ($this->compatibleEquipment as $index => $equipment) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $content[$index] = sprintf(" %s %-58s :%02d", $prefix, "{$equipment->icon} {$equipment->name}", $equipment->quantity);
    }

    $this->state->equipmentAssignmentPanel->setContent($content);
    $this->state->equipmentAssignmentPanel->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->state->equipmentAssignmentPanel->erase();
  }

  /**
   * Handle actions.
   *
   * @return void
   * @throws Exception If an error occurs while alerting the player.
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("back")) {
      $this->state->setMode($this->previousMode ?? throw new RuntimeException('Previous mode cannot be null.'));
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->activeEquipment) {
        $this->character->equip($this->activeEquipment);
      } else {
        $this->character->unequip($this->equipmentSlot);
      }
      $this->state->setMode($this->previousMode);
    }
  }

  /**
   * Handle navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->selectNext();
      } else {
        $this->selectPrevious();
      }
      $this->updateCharacterDetailPanel();
      if ($this->activeEquipment) {
        $this->state->equipmentInfoPanel->setText($this->activeEquipment->description);
      }
      $this->render();
    }
  }

  /**
   * Updates the character detail panel.
   *
   * @return void
   */
  protected function updateCharacterDetailPanel(): void
  {
    $previewStats = new Stats(
      attack: $this->character?->stats->attack + $this->activeEquipment?->parameterChanges->attack,
      defence: $this->character?->stats->defence + $this->activeEquipment?->parameterChanges->defence,
      magicAttack: $this->character?->stats->magicAttack + $this->activeEquipment?->parameterChanges->magicAttack,
      magicDefence: $this->character?->stats->magicDefence + $this->activeEquipment?->parameterChanges->magicDefence,
      speed: $this->character?->stats->speed + $this->activeEquipment?->parameterChanges->speed,
      grace: $this->character?->stats->grace + $this->activeEquipment?->parameterChanges->grace,
      evasion: $this->character?->stats->evasion + $this->activeEquipment?->parameterChanges->evasion,
      totalHp: $this->character?->stats->totalHp + $this->activeEquipment?->parameterChanges->totalHp,
      totalMp: $this->character?->stats->totalMp + $this->activeEquipment?->parameterChanges->totalMp,
    );
    $this->state->characterDetailPanel->setDetails($this->character, $previewStats);
  }
}