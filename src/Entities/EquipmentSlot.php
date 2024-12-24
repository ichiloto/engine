<?php

namespace Ichiloto\Engine\Entities;

use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Util\Debug;

/**
 * The EquipmentSlot class.
 *
 * @package Ichiloto\Engine\Entities
 */
class EquipmentSlot
{
  /**
   * EquipmentSlot constructor.
   *
   * @param string $name The name of the slot.
   * @param string $description The description of the slot.
   * @param string $icon The icon of the slot.
   * @param string $acceptsType The type of item the slot accepts.
   * @param Equipment|null $equipment The equipment in the slot.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $description,
    protected(set) string $icon,
    protected(set) string $acceptsType = Weapon::class,
    public ?InventoryItem $equipment = null {
      get {
        return $this->equipment;
      }
      set {
        if ($value !== null && !($value instanceof $this->acceptsType)) {
          Debug::warn("Item type mismatch. Expected: {$this->acceptsType}, got: " . get_class($value));
        } else {
          $this->equipment = $value;
        }
      }
    }
  )
  {
  }
}