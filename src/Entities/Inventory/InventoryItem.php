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
   * The maximum quantity of an item.
   */
  public const int MAX_QUANTITY = 99;

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
   * @param ItemUserType $userType The user type of the item.
   * @param bool $isKeyItem Whether the item is a key item.
   * @param bool $consumable Whether the item is consumable.
   * @param int $maxQuantity The maximum quantity of the item. Defaults to 99.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) string $description,
    protected(set) string $icon,
    public int $price,
    public int $quantity = 1 {
      get {
        return $this->quantity;
      }
      set {
        $this->quantity = clamp($value, 0, $this->maxQuantity ?? self::MAX_QUANTITY);
      }
    },
    protected(set) ItemUserType $userType = ItemUserType::ALL,
    protected(set) bool $isKeyItem = false,
    protected(set) bool $consumable = false,
    protected(set) int $maxQuantity = self::MAX_QUANTITY,
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

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return $this->name;
  }

  /**
   * Creates an inventory item from an array.
   *
   * @param array<string, mixed> $data The data.
   * @return static The inventory item.
   */
  public static abstract function fromArray(array $data): static;

  /**
   * Creates an inventory item from an object.
   *
   * @param object $data The data.
   * @return static The inventory item.
   */
  public static function fromObject(object $data): static
  {
    return static::fromArray((array) $data);
  }
}