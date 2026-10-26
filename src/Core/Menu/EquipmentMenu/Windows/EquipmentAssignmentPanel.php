<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows;

use Ichiloto\Engine\Core\Area;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\UI\Presentation\CharacterMenuRows;
use Ichiloto\Engine\UI\Presentation\SummonEquipmentSlot;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the equipment assignment panel.
 *
 * @package Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows
 */
class EquipmentAssignmentPanel extends Window
{
  /**
   * @var int The active slot index.
   */
  protected(set) int $activeSlotIndex = -1;
  /**
   * @var EquipmentSlot|null The active slot.
   */
  public ?EquipmentSlot $activeSlot {
    get {
      return $this->slots[$this->activeSlotIndex] ?? null;
    }
  }

  /**
   * @var EquipmentSlot[] The slots to display in the panel.
   */
  protected array $slots = [];
  protected ?Character $character = null;
  /**
   * @var int The total number of slots.
   */
  protected int $totalSlots = 0;
  private ?SummonEquipmentSlot $summonSlot = null;

  public function isSummonSlotSelected(): bool
  {
    return $this->summonSlot !== null && $this->activeSlotIndex === count($this->slots);
  }

  /**
   * Create a new EquipmentAssignmentPanel instance.
   *
   * @param MenuInterface $menu The menu that the panel belongs to.
   * @param Rect $area The area of the panel.
   * @param BorderPackInterface $borderPack The border pack to use for the panel.
   */
  public function __construct(
    protected MenuInterface $menu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
      '',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * Set the slots to display in the panel.
   *
   * @param EquipmentSlot[] $slots The slots to display in the panel.
   * @return void
   */
  public function setSlots(array $slots, ?Character $character = null, ?SummonEquipmentSlot $summonSlot = null): void
  {
    $this->slots = $slots;
    $this->character = $character;
    $this->summonSlot = $summonSlot;
    $this->totalSlots = count($slots) + ($summonSlot === null ? 0 : 1);
    $this->activeSlotIndex = min($this->activeSlotIndex, $this->totalSlots - 1);
    $this->updateContent();
  }

  /**
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];

    foreach ($this->slots as $index => $slot) {
      $prefix = $index === $this->activeSlotIndex ? '>' : ' ';
      $type = $this->character === null ? $slot->semanticSlot : CharacterMenuRows::getSlotIcon($this->character, $slot);
      $slotName = TerminalMenuRow::composeIconLabel(EquipmentIcon::getTerminalGlyph($type), "{$slot->name}:", 2);
      $equippedItem = $slot->equipment?->name ?? '';
      $content[] = TerminalMenuRow::compose($slotName, $equippedItem, $this->getContentWidth(),
        prefix: " {$prefix} ", valueAlignment: 'left', labelWidth: 20);
    }

    if ($this->summonSlot !== null) {
      $content[] = TerminalMenuRow::compose($this->summonSlot->getLabel() . ':', implode(', ', $this->summonSlot->getAssignedNames()),
        $this->getContentWidth(), prefix: $this->isSummonSlotSelected() ? ' > ' : '   ', valueAlignment: 'left', labelWidth: 20);
    }
    $content = array_pad($content, $this->height - 2, '');

    $this->setContent($content);
    $this->render();
  }

  /**
   * Set the active slot by index.
   *
   * @param int $index The index of the slot to set as active.
   * @return void
   */
  public function setActiveSlotByIndex(int $index): void
  {
    $this->activeSlotIndex = $index;
    $this->updateContent();
  }

  public function selectPreviousSlot(): void
  {
    if ($this->totalSlots === 0) { return; }
    $index = wrap($this->activeSlotIndex - 1, 0, $this->totalSlots - 1);
    $this->setActiveSlotByIndex($index);
  }

  /**
   * Select the next slot.
   *
   * @return void
   */
  public function selectNextSlot(): void
  {
    if ($this->totalSlots === 0) { return; }
    $index = wrap($this->activeSlotIndex + 1, 0, $this->totalSlots - 1);
    $this->setActiveSlotByIndex($index);
  }

  /**
   * Get the size of the panel.
   *
   * @return Area
   */
  public function getSize(): Area
  {
    return new Area($this->width, $this->height);
  }
}
