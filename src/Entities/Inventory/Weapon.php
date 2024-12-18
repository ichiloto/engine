<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Weapon class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Weapon extends InventoryItem
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
      $data['icon'] ?? throw new RequiredFieldException('icon'),
      $data['price'] ?? throw new RequiredFieldException('price'),
      $data['quantity'] ?? 1,
      $userType,
      $data['isKeyItem'] ?? false,
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