<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\ParameterChanges;

abstract class Equipment extends InventoryItem
{
  protected(set) EquipmentSlotType $semanticSlot;
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
    protected(set) array $elementAffinities = [],
    protected(set) ?string $element = null,
    ?string $id = null,
    ?EquipmentSlotType $semanticSlot = null,
    protected(set) ?string $form = null,
    protected(set) ?string $size = null,
    protected(set) ?string $material = null,
    protected(set) int $accuracyModifier = 0,
    protected(set) int $criticalModifier = 0,
    protected(set) ?array $specialProperty = null,
    bool $sellable = true,
    int $sellRateBasisPoints = 5000,
    array $aliases = [],
    string $availability = 'ordinary',
    ?string $acquisitionPolicy = null,
  )
  {
    $this->semanticSlot = $semanticSlot ?? match (true) {
      $this instanceof \Ichiloto\Engine\Entities\Inventory\Weapons\Weapon => EquipmentSlotType::WEAPON,
      $this instanceof Accessory => EquipmentSlotType::ACCESSORY,
      default => EquipmentSlotType::BODY,
    };

    parent::__construct(
      $name,
      $description,
      $icon,
      $price,
      $quantity,
      $userType,
      $isKeyItem,
      $consumable,
      id: $id,
      sellable: $sellable,
      sellRateBasisPoints: $sellRateBasisPoints,
      aliases: $aliases,
      availability: $availability,
      acquisitionPolicy: $acquisitionPolicy,
    );

    if ($this->accuracyModifier < -100 || $this->accuracyModifier > 100
      || $this->criticalModifier < -100 || $this->criticalModifier > 100) {
      throw new \InvalidArgumentException('Equipment accuracy and critical modifiers must be between -100 and 100.');
    }
  }

  /**
   * Returns this piece's multiplier against an element.
   *
   * The same semantics as a battler's affinities: 2.0 weak, 0.5 resist,
   * 0.0 null, negative absorbs. Unlisted elements are neutral, and lookup
   * ignores case so authored data never fails on capitalisation.
   *
   * @param string|null $element The element name; null is always neutral.
   * @return float The multiplier.
   */
  public function getElementMultiplier(?string $element): float
  {
    if ($element === null || trim($element) === '') {
      return 1.0;
    }

    foreach ($this->elementAffinities as $affinityElement => $multiplier) {
      if (strcasecmp(trim(strval($affinityElement)), trim($element)) === 0) {
        return floatval($multiplier);
      }
    }

    return 1.0;
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

  protected function copySubtypeDefinitionFrom(InventoryItem $prototype): void
  {
    if (! $prototype instanceof Equipment) {
      throw new \InvalidArgumentException('Equipment definition subtype mismatch.');
    }

    $this->parameterChanges = clone $prototype->parameterChanges;
    $this->equipmentType = $prototype->equipmentType;
    $this->elementAffinities = $prototype->elementAffinities;
    $this->element = $prototype->element;
    $this->semanticSlot = $prototype->semanticSlot;
    $this->form = $prototype->form;
    $this->size = $prototype->size;
    $this->material = $prototype->material;
    $this->accuracyModifier = $prototype->accuracyModifier;
    $this->criticalModifier = $prototype->criticalModifier;
    $this->specialProperty = $prototype->specialProperty;
  }
}
