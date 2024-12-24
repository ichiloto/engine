<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\ParameterChanges;

abstract class Equipment extends InventoryItem
{
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
  )
  {
    parent::__construct($name, $description, $icon, $price, $quantity, $userType, $isKeyItem, $consumable);
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
    $netRatingForA = 0;
    $netRatingForB = 0;

    if ($netRatingForA > $netRatingForB) {
      return $a;
    } else if ($netRatingForB > $netRatingForA) {
      return $b;
    }

    return null;
  }
}