<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Armor class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Armor extends Equipment
{
  /**
   * @inheritDoc
   */
  public static function fromArray(array $data): static
  {
    $userType = $data['userType'] ?? ItemUserType::ALL;
    if (is_string($userType)) {
      $userType = ItemUserType::tryFrom($userType) ?? ItemUserType::ALL;
    }

    return new self(
      $data['name'] ?? throw new RequiredFieldException('name'),
      $data['description'] ?? throw new RequiredFieldException('description'),
      $data['icon'] ?? '🛡️',
      $data['price'] ?? 0,
      $data['quantity'] ?? 1,
      $userType,
      $data['isKeyItem'] ?? false,
      false,
      $data['parameterChanges'] ?? new ParameterChanges(),
      Equipment::resolveEquipmentType($data['type'] ?? null, isWeapon: false),
      is_array($data['elementAffinities'] ?? null) ? $data['elementAffinities'] : [],
      isset($data['element']) ? strval($data['element']) : null,
      $data['id'] ?? null,
      EquipmentSlotType::require($data['slot'] ?? 'body'),
      isset($data['form']) ? strval($data['form']) : null,
      isset($data['size']) ? strval($data['size']) : null,
      isset($data['material']) ? strval($data['material']) : null,
      intval($data['accuracyModifier'] ?? 0),
      intval($data['criticalModifier'] ?? 0),
      is_array($data['specialProperty'] ?? null) ? $data['specialProperty'] : null,
      boolval($data['sellable'] ?? true),
      intval($data['sellRateBasisPoints'] ?? 5000),
      is_array($data['aliases'] ?? null) ? $data['aliases'] : [],
      strval($data['availability'] ?? 'ordinary'),
      isset($data['acquisitionPolicy']) ? strval($data['acquisitionPolicy']) : null,
    );
  }

  /**
   * @inheritdoc
   * @throws RequiredFieldException
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }
}
