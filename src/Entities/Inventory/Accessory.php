<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Exceptions\NotImplementedException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Accessory class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Accessory extends Equipment
{
  public static function fromArray(array $data): static
  {
    // TODO: Implement fromArray() method.
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

    );
  }
}