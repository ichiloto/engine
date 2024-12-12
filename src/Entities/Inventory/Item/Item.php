<?php

namespace Ichiloto\Engine\Entities\Inventory\Item;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

/**
 * The Item class.
 *
 * @package Ichiloto\Engine\Entities\Inventory\Item
 */
class Item extends InventoryItem
{
  /**
   * The Item constructor.
   *
   * @param string $name The name.
   * @param string $description The description.
   * @param string $icon The icon.
   * @param int $price The price.
   * @param int $quantity The quantity. Default is 1.
   * @param ItemUserType $userType The user type. Default is ItemUserType::ALL.
   * @param bool $consumable Whether the item is consumable.
   * @param ItemScope $scope The scope.
   * @param Occasion $occasion The occasion.
   */
  public function __construct(
    string $name,
    string $description,
    string $icon,
    int $price,
    int $quantity = 1,
    ItemUserType $userType = ItemUserType::ALL,
    protected(set) bool $consumable = true,
    protected(set) ItemScope $scope = new ItemScope(),
    protected(set) Occasion $occasion = Occasion::ALWAYS,
  )
  {
    parent::__construct(
      $name,
      $description,
      $icon,
      $price,
      $quantity,
      $userType
    );
  }

  /**
   * Tries to create an instance of the Item class from the given data.
   *
   * @param array<string, mixed> $data The data.
   * @return self The new instance.
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function fromArray(array $data): static
  {
    $name = $data['name'] ?? throw new RequiredFieldException('name');
    $description = $data['description'] ?? throw new RequiredFieldException('description');
    $icon = $data['icon'] ?? throw new RequiredFieldException('icon');
    $price = $data['price'] ?? throw new RequiredFieldException('price');
    $quantity = $data['quantity'] ?? 1;
    $userType = $data['userType'] ?? ItemUserType::ALL;
    if (is_string($userType)) {
      $userType = ItemUserType::tryFrom($userType);
    }
    $scope = ItemScope::fromObject($data['scope'] ?? (object)[]);

    return new self(
      $name,
      $description,
      $icon,
      $price,
      $quantity,
      $userType,
      true,
      $scope
    );
  }

  /**
   * Tries to create an instance of the Item class from the given object.
   *
   * @param object $data The data.
   * @return self The new instance.
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }
}