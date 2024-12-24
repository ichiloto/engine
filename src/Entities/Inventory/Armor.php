<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
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
      $data['parameterChanges'] ?? new ParameterChanges()
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