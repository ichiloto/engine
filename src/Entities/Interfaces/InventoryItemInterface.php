<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Stringable;

/**
 * Represents an inventory item.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface InventoryItemInterface extends CanCompare, Stringable
{
  /**
   * @var string $name The name of the item.
   */
  public string $name {
    get;
  }

  /**
   * @var string $description The description of the item.
   */
  public string $description {
    get;
  }

  /**
   * @var string $icon The icon of the item.
   */
  public string $icon {
    get;
  }

  /**
   * @var int $price The price of the item.
   */
  public int $price {
    get;
  }

  /**
   * @var int $quantity The quantity of the item.
   */
  public int $quantity {
    get;
    set;
  }

  /**
   * @var ItemUserType $userType The user type of the item.
   */
  public ItemUserType $userType {
    get;
  }

  /**
   * @var bool $isKeyItem Whether the item is a key item.
   */
  public bool $isKeyItem {
    get;
  }
}