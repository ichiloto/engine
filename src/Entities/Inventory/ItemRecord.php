<?php

namespace Ichiloto\Engine\Entities\Inventory;

use BackedEnum;
use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Effects\HPDamageEffect;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\MaxHPIncrementEffect;
use Ichiloto\Engine\Entities\Effects\MaxMPIncrementEffect;
use Ichiloto\Engine\Entities\Effects\MPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use InvalidArgumentException;
use ReflectionProperty;

/**
 * The data form of an inventory definition (an item, a weapon, an armor or
 * an accessory), as one record file authors it.
 *
 * A record holds plain values only: its kind, its stable id, what it is
 * called and costs, who may use it, how it trades, and for an item whom it
 * reaches, when, and its effects (each a `type` with that type's values),
 * or for equipment its slot, type, stat changes, shape, elements and
 * modifiers. Writing a record leaves out values equal to what reading would
 * assume, so a record states only what its author chose; the id is always
 * written, so a definition keeps it however it was first derived.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
final class ItemRecord
{
  /** Each kind's value, by the class it builds. */
  public const array KINDS = [
    'item' => Item::class,
    'weapon' => Weapon::class,
    'armor' => Armor::class,
    'accessory' => Accessory::class,
  ];

  /** An item's effects, by type. */
  public const array EFFECTS = [
    'hp_recovery' => HPRecoveryEffect::class,
    'mp_recovery' => MPRecoveryEffect::class,
    'resurrection' => ResurrectionEffect::class,
    'hp_damage' => HPDamageEffect::class,
    'max_hp_increment' => MaxHPIncrementEffect::class,
    'max_mp_increment' => MaxMPIncrementEffect::class,
  ];

  /** The keys every kind's data may hold. */
  private const array COMMON_KEYS = [
    'kind', 'id', 'name', 'description', 'icon', 'price', 'quantity', 'userType', 'isKeyItem', 'consumable',
    'sellable', 'sellRateBasisPoints', 'aliases', 'availability', 'acquisitionPolicy',
  ];

  /** The keys only an item's data holds. */
  private const array ITEM_KEYS = ['scope', 'occasion', 'effects', 'animationId'];

  /** The keys only equipment's data holds. */
  private const array EQUIPMENT_KEYS = [
    'semanticSlot', 'equipmentType', 'parameterChanges', 'elementAffinities', 'element', 'form', 'size', 'material',
    'accuracyModifier', 'criticalModifier', 'specialProperty',
  ];

  /** The stats parameter changes are made of, in their constructor's order. */
  private const array PARAMETERS = ['attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion', 'totalHp', 'totalMp'];

  /**
   * Builds a definition from its record data.
   *
   * @param array<string, mixed> $data The record's data.
   * @return InventoryItem The definition.
   * @throws InvalidArgumentException If the data is not a valid inventory record.
   */
  public static function readItem(array $data): InventoryItem
  {
    $kind = self::requireString($data, 'kind');
    $class = self::KINDS[$kind]
      ?? throw new InvalidArgumentException(sprintf('kind "%s" is not one of %s.', $kind, implode(', ', array_keys(self::KINDS))));
    $isItem = $class === Item::class;
    $allowed = [...self::COMMON_KEYS, ...($isItem ? self::ITEM_KEYS : self::EQUIPMENT_KEYS)];

    foreach (array_keys($data) as $key) {
      if (! in_array($key, $allowed, true)) {
        throw new InvalidArgumentException(sprintf('Unknown %s record key "%s".', $kind, $key));
      }
    }

    $arguments = [
      'name' => self::requireString($data, 'name'),
      'description' => self::requireText($data, 'description'),
      'icon' => self::requireText($data, 'icon'),
      'price' => self::requireInt($data, 'price'),
      'quantity' => self::readInt($data, 'quantity', 1),
      'userType' => self::readEnum(ItemUserType::class, $data, 'userType', ItemUserType::ALL),
      'isKeyItem' => self::readBool($data, 'isKeyItem', false),
      'consumable' => self::readBool($data, 'consumable', $isItem),
      'id' => self::requireString($data, 'id'),
      'sellable' => self::readBool($data, 'sellable', true),
      'sellRateBasisPoints' => self::readInt($data, 'sellRateBasisPoints', 5000),
      'aliases' => self::readStrings($data, 'aliases'),
      'availability' => array_key_exists('availability', $data) ? self::requireString($data, 'availability') : 'ordinary',
      'acquisitionPolicy' => self::readOptionalString($data, 'acquisitionPolicy'),
    ];

    if ($isItem) {
      return new Item(...[
        ...$arguments,
        'scope' => self::readScope($data['scope'] ?? []),
        'occasion' => self::readEnum(Occasion::class, $data, 'occasion', Occasion::ALWAYS),
        'effects' => self::readEffects($data['effects'] ?? []),
        'animationId' => array_key_exists('animationId', $data) ? self::requireInt($data, 'animationId') : null,
      ]);
    }

    $equipmentType = match ($class) {
      Weapon::class => self::readEnum(WeaponType::class, $data, 'equipmentType', null),
      Armor::class => self::readEnum(ArmorType::class, $data, 'equipmentType', null),
      default => array_key_exists('equipmentType', $data)
        ? throw new InvalidArgumentException('An accessory has no equipment type.')
        : null,
    };

    return new $class(...[
      ...$arguments,
      'parameterChanges' => self::readParameterChanges($data['parameterChanges'] ?? []),
      'equipmentType' => $equipmentType,
      'elementAffinities' => self::readAffinities($data['elementAffinities'] ?? []),
      'element' => self::readOptionalString($data, 'element'),
      'semanticSlot' => self::readEnum(EquipmentSlotType::class, $data, 'semanticSlot', null),
      'form' => self::readOptionalString($data, 'form'),
      'size' => self::readOptionalString($data, 'size'),
      'material' => self::readOptionalString($data, 'material'),
      'accuracyModifier' => self::readInt($data, 'accuracyModifier', 0),
      'criticalModifier' => self::readInt($data, 'criticalModifier', 0),
      'specialProperty' => self::readSpecialProperty($data['specialProperty'] ?? null),
    ]);
  }

  /**
   * Returns a definition's record data.
   *
   * @param InventoryItem $item The definition.
   * @return array<string, mixed> The record's data.
   * @throws InvalidArgumentException When the definition holds what a record cannot.
   */
  public static function writeItem(InventoryItem $item): array
  {
    $kind = array_search($item::class, self::KINDS, true)
      ?: throw new InvalidArgumentException(sprintf('%s is a %s, which is not an inventory kind a record names.', $item->name, $item::class));
    $isItem = $item instanceof Item;
    // The icon as authored: equipment shows its type's icon, but keeps the
    // glyph its author wrote, which reading the property would not give.
    $icon = new ReflectionProperty(InventoryItem::class, 'icon')->getRawValue($item);
    $data = [
      'kind' => $kind,
      'id' => $item->id,
      'name' => $item->name,
      'description' => $item->description,
      'icon' => is_string($icon) ? $icon : '',
      'price' => $item->price,
    ];
    $optional = [
      'quantity' => [$item->quantity, 1],
      'userType' => [$item->userType->value, ItemUserType::ALL->value],
      'isKeyItem' => [$item->isKeyItem, false],
      'consumable' => [$item->consumable, $isItem],
      'sellable' => [$item->sellable, true],
      'sellRateBasisPoints' => [$item->sellRateBasisPoints, 5000],
      'aliases' => [array_values($item->aliases), []],
      'availability' => [$item->availability, 'ordinary'],
      'acquisitionPolicy' => [$item->acquisitionPolicy, null],
    ];

    foreach ($optional as $key => [$value, $default]) {
      if ($value !== $default) {
        $data[$key] = $value;
      }
    }

    if ($item instanceof Item) {
      $scope = self::writeScope($item->scope);

      if ($scope !== []) {
        $data['scope'] = $scope;
      }

      if ($item->occasion !== Occasion::ALWAYS) {
        $data['occasion'] = $item->occasion->value;
      }

      if ($item->effects !== []) {
        $data['effects'] = array_map(self::writeEffect(...), array_values($item->effects));
      }

      if ($item->animationId !== null) {
        $data['animationId'] = $item->animationId;
      }

      return $data;
    }

    assert($item instanceof Equipment);
    $defaultSlot = match (true) {
      $item instanceof Weapon => EquipmentSlotType::WEAPON,
      $item instanceof Accessory => EquipmentSlotType::ACCESSORY,
      default => EquipmentSlotType::BODY,
    };

    if ($item->semanticSlot !== $defaultSlot) {
      $data['semanticSlot'] = $item->semanticSlot->value;
    }

    if ($item->equipmentType !== null) {
      $data['equipmentType'] = $item->equipmentType->value;
    }

    $changes = [];

    foreach (self::PARAMETERS as $parameter) {
      if ($item->parameterChanges->$parameter !== 0) {
        $changes[$parameter] = $item->parameterChanges->$parameter;
      }
    }

    if ($changes !== []) {
      $data['parameterChanges'] = $changes;
    }

    $equipment = [
      'elementAffinities' => [$item->elementAffinities, []],
      'element' => [$item->element, null],
      'form' => [$item->form, null],
      'size' => [$item->size, null],
      'material' => [$item->material, null],
      'accuracyModifier' => [$item->accuracyModifier, 0],
      'criticalModifier' => [$item->criticalModifier, 0],
      'specialProperty' => [$item->specialProperty, null],
    ];

    foreach ($equipment as $key => [$value, $default]) {
      if ($value !== $default) {
        $data[$key] = $value;
      }
    }

    return $data;
  }

  /** @param mixed $scope */
  private static function readScope(mixed $scope): ItemScope
  {
    if (! is_array($scope)) {
      throw new InvalidArgumentException('scope must hold side, number and status.');
    }

    return new ItemScope(
      self::readEnum(ItemScopeSide::class, $scope, 'side', ItemScopeSide::NONE, 'scope.'),
      self::readEnum(ItemScopeNumber::class, $scope, 'number', ItemScopeNumber::ONE, 'scope.'),
      self::readEnum(ItemScopeStatus::class, $scope, 'status', ItemScopeStatus::ALIVE, 'scope.'),
      self::readInt($scope, 'randomNumber', 1, 'scope.'),
    );
  }

  /** @return array<string, mixed> */
  private static function writeScope(ItemScope $scope): array
  {
    $data = [];
    $defaults = new ItemScope();

    foreach (['side', 'number', 'status'] as $part) {
      if ($scope->$part !== $defaults->$part) {
        $data[$part] = $scope->$part->value;
      }
    }

    if ($scope->randomNumber !== 1) {
      $data['randomNumber'] = $scope->randomNumber;
    }

    return $data;
  }

  /**
   * @return list<BaseEffect>
   */
  private static function readEffects(mixed $effects): array
  {
    if (! is_array($effects) || ! array_is_list($effects)) {
      throw new InvalidArgumentException('effects must be a list.');
    }

    $read = [];

    foreach ($effects as $index => $effect) {
      $path = sprintf('effects.%d.', $index);

      if (! is_array($effect)) {
        throw new InvalidArgumentException(sprintf('%s must be an effect.', rtrim($path, '.')));
      }

      foreach (array_keys($effect) as $key) {
        if (! in_array($key, ['type', 'name', 'description', 'value', 'successRate', 'valueBasis'], true)) {
          throw new InvalidArgumentException(sprintf('Unknown key "%s%s" for an item effect.', $path, $key));
        }
      }

      $type = self::requireString($effect, 'type', $path);
      $class = self::EFFECTS[$type] ?? throw new InvalidArgumentException(sprintf(
        '%stype "%s" is not one of %s.',
        $path,
        $type,
        implode(', ', array_keys(self::EFFECTS)),
      ));
      $successRate = $effect['successRate'] ?? 1.0;

      if (! is_int($successRate) && ! is_float($successRate)) {
        throw new InvalidArgumentException(sprintf('%ssuccessRate must be a number.', $path));
      }

      if (! array_key_exists('value', $effect)) {
        throw new InvalidArgumentException(sprintf('%svalue is required.', $path));
      }

      $read[] = new $class(
        name: self::requireText($effect, 'name', $path),
        description: self::requireText($effect, 'description', $path),
        value: $effect['value'],
        successRate: floatval($successRate),
        valueBasis: self::readEnum(ValueBasis::class, $effect, 'valueBasis', ValueBasis::ACTUAL, $path),
      );
    }

    return $read;
  }

  /** @return array<string, mixed> */
  private static function writeEffect(BaseEffect $effect): array
  {
    $type = array_search($effect::class, self::EFFECTS, true)
      ?: throw new InvalidArgumentException(sprintf('%s is not an effect an item record can name.', $effect::class));
    $data = [
      'type' => $type,
      'name' => $effect->name,
      'description' => $effect->description,
      'value' => $effect->value,
      'successRate' => $effect->successRate,
    ];

    if ($effect->valueBasis !== ValueBasis::ACTUAL) {
      $data['valueBasis'] = $effect->valueBasis->value;
    }

    return $data;
  }

  private static function readParameterChanges(mixed $changes): ParameterChanges
  {
    if (! is_array($changes)) {
      throw new InvalidArgumentException('parameterChanges must map stats to changes.');
    }

    $values = [];

    foreach ($changes as $parameter => $change) {
      if (! in_array($parameter, self::PARAMETERS, true)) {
        throw new InvalidArgumentException(sprintf('parameterChanges.%s is not one of %s.', $parameter, implode(', ', self::PARAMETERS)));
      }

      if (! is_int($change)) {
        throw new InvalidArgumentException(sprintf('parameterChanges.%s must be a whole number.', $parameter));
      }

      $values[$parameter] = $change;
    }

    return new ParameterChanges(...$values);
  }

  /**
   * @return array<string, float>
   */
  private static function readAffinities(mixed $affinities): array
  {
    if (! is_array($affinities)) {
      throw new InvalidArgumentException('elementAffinities must map elements to multipliers.');
    }

    foreach ($affinities as $element => $multiplier) {
      if (! is_string($element) || (! is_int($multiplier) && ! is_float($multiplier))) {
        throw new InvalidArgumentException('elementAffinities must map elements to multipliers.');
      }
    }

    return $affinities;
  }

  /**
   * @return array<string, mixed>|null
   */
  private static function readSpecialProperty(mixed $property): ?array
  {
    if ($property !== null && ! is_array($property)) {
      throw new InvalidArgumentException('specialProperty must be a set of keys and values.');
    }

    return $property;
  }

  /**
   * @template T of BackedEnum
   * @param class-string<T> $enum
   * @param array<string, mixed> $data
   * @param T|null $default
   * @return T|null
   */
  private static function readEnum(string $enum, array $data, string $key, ?BackedEnum $default, string $path = ''): ?BackedEnum
  {
    if (! array_key_exists($key, $data)) {
      return $default;
    }

    return $enum::tryFrom(strval($data[$key])) ?? throw new InvalidArgumentException(sprintf(
      '%s%s "%s" is not one of %s.',
      $path,
      $key,
      strval($data[$key]),
      implode(', ', array_map(static fn(BackedEnum $case): string => strval($case->value), $enum::cases())),
    ));
  }

  /**
   * @param array<string, mixed> $data
   * @return list<string>
   */
  private static function readStrings(array $data, string $key): array
  {
    $values = $data[$key] ?? [];

    if (! is_array($values) || ! array_is_list($values) || array_filter($values, static fn(mixed $value): bool => ! is_string($value)) !== []) {
      throw new InvalidArgumentException(sprintf('%s must be a list of text.', $key));
    }

    return $values;
  }

  /** @param array<string, mixed> $data */
  private static function readOptionalString(array $data, string $key): ?string
  {
    $value = $data[$key] ?? null;

    if ($value !== null && ! is_string($value)) {
      throw new InvalidArgumentException(sprintf('%s must be text.', $key));
    }

    return $value;
  }

  /** @param array<string, mixed> $data */
  private static function readBool(array $data, string $key, bool $default): bool
  {
    $value = $data[$key] ?? $default;

    if (! is_bool($value)) {
      throw new InvalidArgumentException(sprintf('%s must be true or false.', $key));
    }

    return $value;
  }

  /** @param array<string, mixed> $data */
  private static function readInt(array $data, string $key, int $default, string $path = ''): int
  {
    return array_key_exists($key, $data) ? self::requireInt($data, $key, $path) : $default;
  }

  /** @param array<string, mixed> $data */
  private static function requireString(array $data, string $key, string $path = ''): string
  {
    $value = $data[$key] ?? null;

    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException(sprintf('%s%s is required text.', $path, $key));
    }

    return $value;
  }

  /**
   * Text that may be empty, such as an item with no icon.
   *
   * @param array<string, mixed> $data
   */
  private static function requireText(array $data, string $key, string $path = ''): string
  {
    $value = $data[$key] ?? null;

    if (! is_string($value)) {
      throw new InvalidArgumentException(sprintf('%s%s must be text.', $path, $key));
    }

    return $value;
  }

  /** @param array<string, mixed> $data */
  private static function requireInt(array $data, string $key, string $path = ''): int
  {
    $value = $data[$key] ?? null;

    if (! is_int($value)) {
      throw new InvalidArgumentException(sprintf('%s%s must be a whole number.', $path, $key));
    }

    return $value;
  }
}
