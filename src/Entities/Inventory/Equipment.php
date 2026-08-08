<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\ParameterChanges;

abstract class Equipment extends InventoryItem
{
  /**
   * @var int The equipment's net rating across all parameter changes.
   */
  public int $rating {
    get {
      return $this->parameterChanges->attack
        + $this->parameterChanges->defence
        + $this->parameterChanges->magicAttack
        + $this->parameterChanges->magicDefence
        + $this->parameterChanges->speed
        + $this->parameterChanges->grace
        + $this->parameterChanges->evasion
        + $this->parameterChanges->totalHp
        + $this->parameterChanges->totalMp;
    }
  }

  public function __construct(
    string $name,
    string $description,
    string $icon,
    int $price,
    int $quantity = 1,
    ItemUserType $userType = ItemUserType::ALL,
    bool $isKeyItem = false,
    bool $consumable = false,
    protected(set) ParameterChanges $parameterChanges = new ParameterChanges(),
    protected(set) WeaponType|ArmorType|null $equipmentType = null,
  )
  {
    parent::__construct($name, $description, $icon, $price, $quantity, $userType, $isKeyItem, $consumable);
  }

  /**
   * Resolves an authored equipment-type name into its enum case.
   *
   * @param mixed $type The authored `type` entry (a WeaponType/ArmorType case or its name).
   * @param bool $isWeapon True to resolve weapon types, false for armor types.
   * @return WeaponType|ArmorType|null The resolved type, or null when unset/unknown.
   */
  public static function resolveEquipmentType(mixed $type, bool $isWeapon): WeaponType|ArmorType|null
  {
    if ($type instanceof WeaponType || $type instanceof ArmorType) {
      return $type;
    }

    if (! is_string($type) || trim($type) === '') {
      return null;
    }

    return $isWeapon
      ? WeaponType::tryFrom(trim($type))
      : ArmorType::tryFrom(trim($type));
  }

  /**
   * Returns the better rated equipment between two.
   *
   * @param Equipment $a The first equipment to compare.
   * @param Equipment $b The second equipment to compare.
   * @return Equipment|null
   */
  public static function getBetterRated(Equipment $a, Equipment $b): ?Equipment
  {
    return ($b->rating > $a->rating) ? $b : $a;
  }

  public function __clone(): void
  {
    $this->parameterChanges = clone $this->parameterChanges;
  }
}