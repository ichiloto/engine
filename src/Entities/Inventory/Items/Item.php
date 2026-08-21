<?php

namespace Ichiloto\Engine\Entities\Inventory\Items;

use Ichiloto\Engine\Entities\Effects\EffectFactory;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\EffectInterface;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use ReflectionException;

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
   * @param bool $isKeyItem
   * @param bool $consumable Whether the item is consumable.
   * @param ItemScope $scope The scope.
   * @param Occasion $occasion The occasion.
   * @param EffectInterface[] $effects
   */
  public function __construct(
    string $name,
    string $description,
    string $icon,
    int $price,
    int $quantity = 1,
    ItemUserType $userType = ItemUserType::ALL,
    bool $isKeyItem = false,
    bool $consumable = true,
    protected(set) ItemScope $scope = new ItemScope(),
    protected(set) Occasion $occasion = Occasion::ALWAYS,
    protected(set) array $effects = [],
    ?string $id = null,
    bool $sellable = true,
    int $sellRateBasisPoints = 5000,
    array $aliases = [],
    string $availability = 'ordinary',
    ?string $acquisitionPolicy = null,
  )
  {
    // $consumable is deliberately not promoted here: InventoryItem already
    // owns that property, and re-promoting it would let the parent
    // constructor below reset it to the parent's default, silently marking
    // every item non-consumable.
    parent::__construct(
      $name,
      $description,
      $icon,
      $price,
      $quantity,
      $userType,
      $isKeyItem,
      $consumable,
      id: $id,
      sellable: $sellable,
      sellRateBasisPoints: $sellRateBasisPoints,
      aliases: $aliases,
      availability: $availability,
      acquisitionPolicy: $acquisitionPolicy,
    );
  }

  /**
   * Tries to create an instance of the Item class from the given data.
   *
   * @param array<string, mixed> $data The data.
   * @return self The new instance.
   * @throws RequiredFieldException If a required field is missing.
   * @throws ReflectionException If the effect class does not exist.
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
    $isKeyItem = $data['isKeyItem'] ?? false;
    $scope = ItemScope::fromObject($data['scope'] ?? (object)[]);
    $occasion = Occasion::tryFrom($data['occasion'] ?? '') ?? Occasion::ALWAYS;
    $effects = EffectFactory::createFromObjects($data['effects'] ?? []);

    return new self(
      $name,
      $description,
      $icon,
      $price,
      $quantity,
      $userType,
      $isKeyItem,
      true,
      $scope,
      $occasion,
      $effects,
      $data['id'] ?? null,
      boolval($data['sellable'] ?? true),
      intval($data['sellRateBasisPoints'] ?? 5000),
      is_array($data['aliases'] ?? null) ? $data['aliases'] : [],
      strval($data['availability'] ?? 'ordinary'),
      isset($data['acquisitionPolicy']) ? strval($data['acquisitionPolicy']) : null,
    );
  }

  /**
   * Tries to create an instance of the Item class from the given object.
   *
   * @param object $data The data.
   * @return self The new instance.
   * @throws ReflectionException If the effect class does not exist.
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }

  public function __clone(): void
  {
    $this->scope = clone $this->scope;
  }

  protected function copySubtypeDefinitionFrom(InventoryItem $prototype): void
  {
    if (! $prototype instanceof self) {
      throw new \InvalidArgumentException('Item definition subtype mismatch.');
    }

    $this->scope = clone $prototype->scope;
    $this->occasion = $prototype->occasion;
    $this->effects = $prototype->effects;
  }
}
