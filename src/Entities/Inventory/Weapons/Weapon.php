<?php

namespace Ichiloto\Engine\Entities\Inventory\Weapons;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Weapon class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Weapon extends Equipment
{
  /**
   * @inheritdoc
   * @throws RequiredFieldException If a required field is missing.
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
      $data['icon'] ?? '⚔️',
      $data['price'] ?? 0,
      $data['quantity'] ?? 1,
      $userType,
      $data['isKeyItem'] ?? false,
      false,
      $data['parameterChanges'] ?? new ParameterChanges()
    );
  }

  /**
   * @inheritdoc
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }
}