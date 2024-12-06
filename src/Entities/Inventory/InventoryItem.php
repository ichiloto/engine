<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use InvalidArgumentException;

/**
 * The InventoryItem class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
abstract class InventoryItem implements InventoryItemInterface
{
  /**
   * @var string $hash The hash of the item.
   */
  public string $hash {
    get {
      return $this->hash;
    }
  }

  /**
   * The InventoryItem constructor.
   *
   * @param string $name The name of the item.
   * @param string $description The description of the item.
   * @param string $icon The icon of the item.
   * @param int $price The price of the item.
   * @param int $quantity The quantity of the item.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $description,
    protected(set) string $icon,
    protected(set) int $price,
    public int $quantity = 1,
    protected(set) ItemUserType $userType = ItemUserType::ALL
  )
  {
    $this->hash = md5(self::class . $name . $description . $icon . $price);
  }

  /**
   * @inheritDoc
   */
  public function compareTo(CanCompare $other): int
  {
    if (! $other instanceof InventoryItemInterface) {
      throw new InvalidArgumentException('The other item must be an instance of ' . InventoryItemInterface::class . '.');
    }

    return $this->hash <=> $other->hash;
  }

  /**
   * @inheritDoc
   */
  public function greaterThan(CanCompare $other): bool
  {
    return $this->compareTo($other) > 0;
  }

  /**
   * @inheritDoc
   */
  public function greaterThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) >= 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThan(CanCompare $other): bool
  {
    return $this->compareTo($other) < 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) <= 0;
  }

  /**
   * @inheritDoc
   */
  public function equals(CanEquate $equatable): bool
  {
    assert($equatable instanceof CanCompare);
    return $this->compareTo($equatable) === 0;
  }

  /**
   * @inheritDoc
   */
  public function notEquals(CanEquate $equatable): bool
  {
    return ! $this->equals($equatable);
  }
}