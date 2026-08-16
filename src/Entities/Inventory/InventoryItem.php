<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\IO\SaveCompatibility\SaveHydrationContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/**
 * The InventoryItem class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
abstract class InventoryItem implements InventoryItemInterface
{
  /** @var array<string, mixed>|null Raw legacy/reference payload during save migration. */
  private ?array $deferredSaveData = null;
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

  /** Stable project definition identity. Display text is never identity. */
  protected(set) string $id;

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
    ?string $id = null,
    protected(set) bool $sellable = true,
    protected(set) int $sellRateBasisPoints = 5000,
    protected(set) array $aliases = [],
    protected(set) string $availability = 'ordinary',
    protected(set) ?string $acquisitionPolicy = null,
  )
  {
    $this->id = self::normalizeDefinitionId($id ?? self::legacyDefinitionId($name));
    $this->hash = $this->id;

    if ($this->sellRateBasisPoints < 0 || $this->sellRateBasisPoints > 10_000) {
      throw new InvalidArgumentException('Item sell rate must be between 0 and 10000 basis points.');
    }
  }

  /** Deterministic integral sale value; half-up rounding occurs exactly once. */
  public int $sellValue {
    get {
      if (! $this->sellable || $this->isKeyItem) {
        return 0;
      }

      return intdiv(($this->price * $this->sellRateBasisPoints) + 5000, 10_000);
    }
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
   * Applies an explicit compatibility alias to the persisted item identity.
   *
   * Existing saves serialize item objects directly, so WP1 preserves that
   * representation and updates only the identity used by current catalogs.
   */
  public function applySaveIdentity(string $name): void
  {
    if ($this->deferredSaveData !== null) {
      $this->deferredSaveData['name'] = trim($name);
      return;
    }

    $this->name = trim($name);
  }

  /** Applies a declared compatibility identity without changing display data. */
  public function applyDefinitionId(string $id): void
  {
    if ($this->deferredSaveData !== null) {
      $this->deferredSaveData['definitionId'] = trim($id);
      return;
    }

    $this->id = self::normalizeDefinitionId($id);
    $this->hash = $this->id;
  }

  protected static function normalizeDefinitionId(string $id): string
  {
    $id = strtolower(trim($id));

    if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
      throw new InvalidArgumentException(sprintf('Invalid inventory definition id: %s.', $id));
    }

    return $id;
  }

  /** Compatibility-only identity for projects not yet declaring explicit IDs. */
  protected static function legacyDefinitionId(string $name): string
  {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return 'legacy.' . ($slug !== '' ? $slug : hash('sha256', $name));
  }

  /** Version 4 persistence: definition identity plus genuine mutable state only. */
  public function __serialize(): array
  {
    return [
      'definitionId' => $this->id,
      'quantity' => $this->quantity,
      'mutableState' => $this->mutableInstanceState(),
    ];
  }

  /** @param array<string, mixed> $data */
  public function __unserialize(array $data): void
  {
    if (SaveHydrationContext::shouldDeferInventoryItems()) {
      $this->deferredSaveData = $data;
      return;
    }

    $this->completeDeferredSaveHydration($data);
  }

  /** @return array<string, mixed>|null */
  public function getDeferredSaveData(): ?array
  {
    return $this->deferredSaveData;
  }

  /** Reconstructs static fields from the current project definition. */
  public function completeDeferredSaveHydration(?array $data = null): void
  {
    $data ??= $this->deferredSaveData;

    if (! is_array($data)) {
      return;
    }

    $reference = trim(strval($data['definitionId'] ?? $data['id'] ?? $data['name'] ?? ''));
    $store = ConfigStore::get(ItemStore::class);

    if (! $store instanceof ItemStore || $reference === '') {
      throw new InvalidArgumentException(sprintf(
        'Inventory definition "%s" cannot be reconstructed because the project item store is unavailable.',
        $reference,
      ));
    }

    $prototype = $store->get($reference);

    if (! $prototype instanceof static) {
      throw new InvalidArgumentException(sprintf(
        'Inventory definition "%s" does not match saved subtype %s.',
        $reference,
        static::class,
      ));
    }

    $quantity = $data['quantity'] ?? 1;

    if (! is_int($quantity)) {
      throw new InvalidArgumentException(sprintf('Inventory definition "%s" has a non-integer quantity.', $reference));
    }

    $this->copyDefinitionFrom($prototype);
    $this->quantity = $quantity;
    $this->restoreMutableInstanceState(is_array($data['mutableState'] ?? null) ? $data['mutableState'] : []);
    $this->deferredSaveData = null;
  }

  /** @return array<string, mixed> */
  protected function mutableInstanceState(): array
  {
    return [];
  }

  /** @param array<string, mixed> $state */
  protected function restoreMutableInstanceState(array $state): void
  {
  }

  protected function copyDefinitionFrom(InventoryItem $prototype): void
  {
    $this->id = $prototype->id;
    $this->hash = $prototype->hash;
    $this->name = $prototype->name;
    $this->description = $prototype->description;
    $this->icon = $prototype->icon;
    $this->price = $prototype->price;
    $this->userType = $prototype->userType;
    $this->isKeyItem = $prototype->isKeyItem;
    $this->consumable = $prototype->consumable;
    $this->maxQuantity = $prototype->maxQuantity;
    $this->sellable = $prototype->sellable;
    $this->sellRateBasisPoints = $prototype->sellRateBasisPoints;
    $this->aliases = $prototype->aliases;
    $this->availability = $prototype->availability;
    $this->acquisitionPolicy = $prototype->acquisitionPolicy;
    $this->copySubtypeDefinitionFrom($prototype);
  }

  protected function copySubtypeDefinitionFrom(InventoryItem $prototype): void
  {
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
