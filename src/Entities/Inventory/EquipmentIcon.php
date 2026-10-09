<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\WeaponType;

/** One type identity for artwork bindings and terminal symbols, independent of individual gear. */
final class EquipmentIcon
{
  public static function getType(Equipment $equipment): WeaponType|EquipmentSlotType
  {
    // Armor families govern compatibility, not visual identity: a magic shield is still a shield.
    return $equipment->semanticSlot === EquipmentSlotType::WEAPON && $equipment->equipmentType instanceof WeaponType
      ? $equipment->equipmentType : $equipment->semanticSlot;
  }

  public static function getRole(WeaponType|EquipmentSlotType $type): string
  {
    $category = match (true) {
      $type instanceof WeaponType => 'weapon',
      default => 'slot',
    };
    return $category . '.' . strtolower(str_replace(' ', '-', $type->value));
  }

  public static function resolveType(WeaponType|EquipmentSlotType|string|null $metadata): WeaponType|EquipmentSlotType|null
  {
    if (!is_string($metadata)) { return $metadata; }
    foreach ([...WeaponType::cases(), ...EquipmentSlotType::cases()] as $type) {
      if (self::getRole($type) === $metadata) { return $type; }
    }
    return null;
  }

  public static function getTerminalGlyph(WeaponType|EquipmentSlotType $type): string
  {
    return match ($type) {
      WeaponType::SWORD, EquipmentSlotType::WEAPON => "\u{2694}\u{FE0F}",
      WeaponType::DAGGER => "\u{1F5E1}\u{FE0F}",
      WeaponType::FLAIL => 'o~',
      WeaponType::AXE => "\u{1FA93}",
      WeaponType::WHIP => '~',
      WeaponType::STAFF => "\u{269A}",
      WeaponType::BOW => "\u{1F3F9}",
      WeaponType::CROSSBOW => '}>',
      WeaponType::GUN => "\u{1F52B}",
      WeaponType::CLAW => "\u{1F43E}",
      WeaponType::GLOVE => "\u{1F94A}",
      WeaponType::SPEAR => "\u{219F}",
      WeaponType::WAND => "\u{1FA84}",
      EquipmentSlotType::BODY => "\u{1F455}",
      EquipmentSlotType::SHIELD => "\u{1F6E1}\u{FE0F}",
      EquipmentSlotType::HEAD => "\u{1FA96}",
      EquipmentSlotType::ACCESSORY => "\u{1F4FF}",
    };
  }

  public static function getItemLabel(InventoryItem $item): string
  {
    return $item instanceof Equipment ? self::getTerminalGlyph(self::getType($item)) . ' ' . $item->name : $item->name;
  }
}
