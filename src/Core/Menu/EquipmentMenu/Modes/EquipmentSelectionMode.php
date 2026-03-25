<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes;

use Exception;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\Console\TerminalText;
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
   * @var array<string, int> The currently available quantities for the selection list.
   */
  protected array $availableQuantities = [];
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
    } else {
      $this->state->equipmentInfoPanel->setText('No compatible equipment available.');
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
    $compatibleEquipment = [];
    $this->availableQuantities = [];
    $currentEquipment = $this->equipmentSlot?->equipment;

    foreach ($inventory->equipment->toArray() as $equipment) {
      assert($equipment instanceof Equipment);

      if (! is_a($equipment, $equipmentType)) {
        continue;
      }

      $availableQuantity = $this->state->party->getAvailableEquipmentQuantity($equipment);
      $this->availableQuantities[$this->getEquipmentKey($equipment)] = $availableQuantity;

      if ($availableQuantity > 0 || $this->equipmentMatches($equipment, $currentEquipment)) {
        $compatibleEquipment[] = $equipment;
      }
    }

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
    if ($this->totalEquipment < 1) {
      return;
    }

    $this->activeIndex = wrap($this->activeIndex - 1, 0, $this->totalEquipment - 1);
  }

  /**
   * Selects the next equipment.
   *
   * @return void
   */
  public function selectNext(): void
  {
    if ($this->totalEquipment < 1) {
      return;
    }

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
      $equipmentName = TerminalText::padRight("{$equipment->icon} {$equipment->name}", 58);
      $quantity = TerminalText::padLeft((string)$this->getAvailableQuantity($equipment), 2);
      $content[$index] = " {$prefix} {$equipmentName} :{$quantity}";
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
        if ($this->isCurrentEquipmentSelected()) {
          $this->character->unequip($this->equipmentSlot ?? throw new RuntimeException('Equipment slot cannot be null.'));
        } else if ($this->getAvailableQuantity($this->activeEquipment) < 1) {
          alert(sprintf('%s is out of stock.', $this->activeEquipment->name));
          return;
        } else {
          $this->character->equipInSlot(
            $this->equipmentSlot ?? throw new RuntimeException('Equipment slot cannot be null.'),
            $this->activeEquipment
          );
        }
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
      } else {
        $this->state->equipmentInfoPanel->setText('No compatible equipment available.');
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
    if (! $this->character) {
      return;
    }

    if (! $this->activeEquipment) {
      $this->state->characterDetailPanel->setDetails($this->character);
      return;
    }

    $previewStats = clone $this->character->effectiveStats;
    $currentEquipment = $this->equipmentSlot?->equipment;

    if ($currentEquipment instanceof Equipment) {
      $this->applyEquipmentChanges($previewStats, $currentEquipment, -1);
    }

    if (! $this->isCurrentEquipmentSelected()) {
      $this->applyEquipmentChanges($previewStats, $this->activeEquipment, 1);
    }

    $this->state->characterDetailPanel->setDetails($this->character, $previewStats);
  }

  /**
   * Applies or removes an equipment's parameter changes from a preview stat block.
   *
   * @param Stats $stats The stats to modify.
   * @param Equipment $equipment The equipment whose parameters should be applied.
   * @param int $direction Use `1` to add the equipment and `-1` to remove it.
   * @return void
   */
  protected function applyEquipmentChanges(Stats $stats, Equipment $equipment, int $direction): void
  {
    $stats->attack += ($equipment->parameterChanges->attack ?? 0) * $direction;
    $stats->defence += ($equipment->parameterChanges->defence ?? 0) * $direction;
    $stats->magicAttack += ($equipment->parameterChanges->magicAttack ?? 0) * $direction;
    $stats->magicDefence += ($equipment->parameterChanges->magicDefence ?? 0) * $direction;
    $stats->speed += ($equipment->parameterChanges->speed ?? 0) * $direction;
    $stats->grace += ($equipment->parameterChanges->grace ?? 0) * $direction;
    $stats->evasion += ($equipment->parameterChanges->evasion ?? 0) * $direction;
    $stats->totalHp += ($equipment->parameterChanges->totalHp ?? 0) * $direction;
    $stats->totalMp += ($equipment->parameterChanges->totalMp ?? 0) * $direction;
  }

  /**
   * Returns the currently available quantity for an equipment entry.
   *
   * @param Equipment $equipment The equipment entry.
   * @return int The number of unequipped copies still available.
   */
  protected function getAvailableQuantity(Equipment $equipment): int
  {
    return $this->availableQuantities[$this->getEquipmentKey($equipment)] ?? 0;
  }

  /**
   * Builds a stable key for availability lookups.
   *
   * @param Equipment $equipment The equipment to identify.
   * @return string The lookup key.
   */
  protected function getEquipmentKey(Equipment $equipment): string
  {
    return $equipment::class . ':' . $equipment->name;
  }

  /**
   * Determines whether the selected item matches what the slot already has equipped.
   *
   * @return bool True if selecting this item should toggle the slot off.
   */
  protected function isCurrentEquipmentSelected(): bool
  {
    return $this->equipmentMatches($this->activeEquipment, $this->equipmentSlot?->equipment);
  }

  /**
   * Compares two equipment entries by type and name.
   *
   * Inventory equipment is stack-based, so matching by class and name is the
   * most reliable way to determine whether two entries represent the same item.
   *
   * @param Equipment|null $first The first equipment entry.
   * @param Equipment|null $second The second equipment entry.
   * @return bool True if both entries represent the same equipment item.
   */
  protected function equipmentMatches(?Equipment $first, ?Equipment $second): bool
  {
    if (! $first instanceof Equipment || ! $second instanceof Equipment) {
      return false;
    }

    return $first::class === $second::class && $first->name === $second->name;
  }
}
