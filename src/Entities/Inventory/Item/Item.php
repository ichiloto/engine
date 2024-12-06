<?php

namespace Ichiloto\Engine\Entities\Inventory\Item;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;

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
    protected(set) bool $consumable = false,
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
}