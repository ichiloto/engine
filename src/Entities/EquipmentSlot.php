<?php

namespace Ichiloto\Engine\Entities;

use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
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
    EquipmentSlotType|string|null $semanticSlot = null,
    public ?InventoryItem $equipment = null {
      get {
        return $this->equipment;
      }
      set {
        $semanticMismatch = $value instanceof Equipment
          && isset($this->semanticSlot)
          && isset($value->semanticSlot)
          && $value->semanticSlot !== $this->semanticSlot;

        if ($value !== null && (!($value instanceof $this->acceptsType)
          || $semanticMismatch)) {
          Debug::warn("Item type mismatch. Expected: {$this->acceptsType}, got: " . get_class($value));
        } else {
          $this->equipment = $value;
        }
      }
    }
  )
  {
    $this->semanticSlot = EquipmentSlotType::require(
      $semanticSlot ?? match (strtolower(trim($this->name))) {
        'weapon' => EquipmentSlotType::WEAPON,
        'shield' => EquipmentSlotType::SHIELD,
        'head' => EquipmentSlotType::HEAD,
        'accessory' => EquipmentSlotType::ACCESSORY,
        default => EquipmentSlotType::BODY,
      }
    );
  }

  protected(set) EquipmentSlotType $semanticSlot;

  /** Version 4 stores the semantic assignment and definition-backed gear only. */
  public function __serialize(): array
  {
    return [
      'semanticSlot' => $this->semanticSlot->value,
      'equipment' => $this->equipment,
    ];
  }

  /** @param array<string, mixed> $data */
  public function __unserialize(array $data): void
  {
    $semantic = EquipmentSlotType::require(
      $data['semanticSlot'] ?? $data['name'] ?? 'body'
    );
    $this->semanticSlot = $semantic;
    $this->name = ucfirst($semantic->value);
    $this->description = sprintf("The actor's %s equipment", $semantic->value);
    $this->icon = match ($semantic) {
      EquipmentSlotType::WEAPON => '⚔️',
      EquipmentSlotType::ACCESSORY => '📿',
      default => '🛡️',
    };
    $this->acceptsType = match ($semantic) {
      EquipmentSlotType::WEAPON => Weapon::class,
      EquipmentSlotType::ACCESSORY => \Ichiloto\Engine\Entities\Inventory\Accessory::class,
      default => \Ichiloto\Engine\Entities\Inventory\Armor::class,
    };
    $equipment = $data['equipment'] ?? null;
    $this->equipment = $equipment instanceof InventoryItem ? $equipment : null;
  }
}
