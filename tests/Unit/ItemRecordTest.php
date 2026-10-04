<?php

use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\MaxHPIncrementEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\ItemCatalog;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;

/** Every stored property, raw, so a hooked property compares as authored. */
function itemRecordProperties(InventoryItem $item): array
{
  $properties = [];

  foreach ((array) $item as $key => $value) {
    $properties[str_replace("\0", '|', $key)] = is_object($value) && ! $value instanceof UnitEnum ? (array) $value : $value;
  }

  ksort($properties);

  return $properties;
}

it('round-trips every kind of definition and every item effect through record data', function () {
  $definitions = [
    new Item('Tonic', 'Restores 50 HP.', '🧪', 50,
      scope: new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE),
      occasion: Occasion::BATTLE_SCREEN,
      effects: [
        new HPRecoveryEffect('Recover 50 HP', 'Recovers 50 HP', 50, 1.0, ValueBasis::ACTUAL),
        new ResurrectionEffect('Revive', 'Revives', 25, 0.5, ValueBasis::PERCENTAGE),
        new MaxHPIncrementEffect('Grow', 'More HP', 5, 1.0, ValueBasis::ACTUAL),
      ],
      id: 'item.tonic', sellRateBasisPoints: 2500, aliases: ['Old Tonic'], animationId: 2),
    new Item('Relic', 'A key.', '', 0, isKeyItem: true, consumable: false, id: 'item.relic', sellable: false, availability: 'story'),
    new Weapon('Short Sword', 'A blade.', '/', 120, parameterChanges: new ParameterChanges(attack: 12, speed: -1),
      equipmentType: WeaponType::SWORD, elementAffinities: ['Fire' => 0.5], element: 'Fire', id: 'equipment.short-sword',
      form: 'blade', size: 'short', material: 'iron', accuracyModifier: 5, criticalModifier: -5,
      specialProperty: ['type' => 'bleed', 'parameters' => ['chance' => 10]], acquisitionPolicy: 'shop'),
    new Armor('Iron Helm', 'A helm.', '^', 80, userType: ItemUserType::CLASS_SPECIFIC, parameterChanges: new ParameterChanges(defence: 4),
      equipmentType: ArmorType::GENERAL_ARMOR, id: 'equipment.iron-helm', semanticSlot: EquipmentSlotType::HEAD),
    new Accessory('Charm', 'Lucky.', '*', 30, parameterChanges: new ParameterChanges(grace: 2), id: 'equipment.charm'),
  ];

  foreach ($definitions as $definition) {
    $data = ItemRecord::writeItem($definition);

    expect(ItemRecord::writeItem(ItemRecord::readItem($data)))->toBe($data)
      ->and(itemRecordProperties(ItemRecord::readItem($data)))->toEqual(itemRecordProperties($definition));
  }

  expect(ItemRecord::writeItem($definitions[0]))->toBe([
    'kind' => 'item',
    'id' => 'item.tonic',
    'name' => 'Tonic',
    'description' => 'Restores 50 HP.',
    'icon' => '🧪',
    'price' => 50,
    'sellRateBasisPoints' => 2500,
    'aliases' => ['Old Tonic'],
    'scope' => ['side' => 'Ally'],
    'occasion' => 'Battle Screen',
    'effects' => [
      ['type' => 'hp_recovery', 'name' => 'Recover 50 HP', 'description' => 'Recovers 50 HP', 'value' => 50, 'successRate' => 1.0],
      ['type' => 'resurrection', 'name' => 'Revive', 'description' => 'Revives', 'value' => 25, 'successRate' => 0.5, 'valueBasis' => 'PERCENTAGE'],
      ['type' => 'max_hp_increment', 'name' => 'Grow', 'description' => 'More HP', 'value' => 5, 'successRate' => 1.0],
    ],
    'animationId' => 2,
  ])
    ->and(ItemRecord::writeItem($definitions[3]))->toMatchArray(['kind' => 'armor', 'userType' => 'Class Specific', 'semanticSlot' => 'head', 'parameterChanges' => ['defence' => 4]]);
});

it('keeps an item\'s id however it was first derived', function () {
  $legacy = new Item('Old Potion', '', '', 10);

  expect(ItemRecord::writeItem($legacy)['id'])->toBe('legacy.old-potion')
    ->and(ItemRecord::readItem(ItemRecord::writeItem($legacy))->id)->toBe('legacy.old-potion');
});

it('refuses data that is not an inventory record, saying where', function (array $data, string $message) {
  expect(fn() => ItemRecord::readItem($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'unknown kind' => [['kind' => 'gem', 'id' => 'x', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0], 'kind "gem" is not one of item, weapon, armor, accessory.'],
  'equipment key on an item' => [['kind' => 'item', 'id' => 'x', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0, 'form' => 'blade'], 'Unknown item record key "form".'],
  'no id' => [['kind' => 'item', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0], 'id is required text.'],
  'unknown effect' => [['kind' => 'item', 'id' => 'x', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0, 'effects' => [['type' => 'teleport', 'name' => '', 'description' => '', 'value' => 1]]], 'effects.0.type "teleport" is not one of'],
  'unknown stat' => [['kind' => 'weapon', 'id' => 'x', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0, 'parameterChanges' => ['luck' => 1]], 'parameterChanges.luck is not one of'],
  'type on an accessory' => [['kind' => 'accessory', 'id' => 'x', 'name' => 'X', 'description' => '', 'icon' => '', 'price' => 0, 'equipmentType' => 'Sword'], 'An accessory has no equipment type.'],
]);

it('reads items, then weapons, then armors, each folder in file order, reporting files it cannot read', function () {
  $root = sys_get_temp_dir() . '/ichiloto-item-catalog-' . bin2hex(random_bytes(4));
  $write = static function (string $file, InventoryItem $item) use ($root): void {
    is_dir(dirname($root . '/assets/Data/' . $file)) || mkdir(dirname($root . '/assets/Data/' . $file), 0777, true);
    file_put_contents($root . '/assets/Data/' . $file, "<?php\nreturn ['class' => \\Ichiloto\\Engine\\Entities\\Inventory\\InventoryItem::class, 'data' => "
      . var_export(ItemRecord::writeItem($item), true) . "];\n");
  };

  try {
    $write('Armors/0001-charm.php', new Accessory('Charm', '', '*', 30, id: 'equipment.charm'));
    $write('Weapons/0001-blade.php', new Weapon('Blade', '', '/', 10, equipmentType: WeaponType::SWORD, id: 'equipment.blade'));
    $write('Items/0002-ether.php', new Item('Ether', '', '', 5, id: 'item.ether'));
    $write('Items/0001-tonic.php', new Item('Tonic', '', '', 5, id: 'item.tonic'));
    $write('Items/0003-tonic-again.php', new Item('Tonic Again', '', '', 5, id: 'item.tonic'));
    file_put_contents($root . '/assets/Data/Items/0004-loose.php', "<?php\nreturn ['name' => 'Loose'];\n");
    $catalog = ItemCatalog::load($root . '/assets');

    expect(array_keys($catalog->getItems()))->toBe(['item.tonic', 'item.ether', 'equipment.blade', 'equipment.charm'])
      ->and($catalog->getSourceFile('equipment.charm'))->toBe('Armors/0001-charm.php')
      ->and($catalog->getProblems())->toBe([
        'Items/0003-tonic-again.php: id "item.tonic" is already defined by Items/0001-tonic.php; inventory ids must be unique, so this file is skipped.',
        "Items/0004-loose.php: an inventory record returns ['class' => InventoryItem::class, 'data' => [...]].",
      ]);

    unlink($root . '/assets/Data/Items/0003-tonic-again.php');
    unlink($root . '/assets/Data/Items/0004-loose.php');

    expect(array_map(static fn(InventoryItem $item): string => $item->name, ItemCatalog::loadProjectItems($root . '/assets')))
      ->toBe(['Tonic', 'Ether', 'Blade', 'Charm']);
  } finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
  }
});
