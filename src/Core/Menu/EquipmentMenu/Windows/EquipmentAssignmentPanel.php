<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Windows;

use Ichiloto\Engine\Core\Area;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\EquipmentSlot;
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
  /**
   * @var int The total number of slots.
   */
  protected int $totalSlots = 0;

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
  public function setSlots(array $slots): void
  {
    $this->slots = $slots;
    $this->totalSlots = count($slots);
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
      $content[] = sprintf(" %s %-20s %s", $prefix, "{$slot->name}:", "{$slot->equipment?->icon} {$slot->equipment?->name}");
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