<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Accessory class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
  * @phpstan-consistent-constructor
 */
class Accessory extends Equipment
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

    return new static(
      $data['name'] ?? throw new RequiredFieldException('name'),
      $data['description'] ?? throw new RequiredFieldException('description'),
      $data['icon'] ?? '📿',
      $data['price'] ?? 0,
      $data['quantity'] ?? 1,
      $userType,
      $data['isKeyItem'] ?? false,
      false,
      $data['parameterChanges'] ?? new ParameterChanges(),
      null,
      is_array($data['elementAffinities'] ?? null) ? $data['elementAffinities'] : [],
      isset($data['element']) ? strval($data['element']) : null,
      $data['id'] ?? null,
      EquipmentSlotType::ACCESSORY,
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
}
