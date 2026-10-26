<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\UI\Presentation\MenuRowPainter;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->savedConfig = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $this->root = sys_get_temp_dir() . '/ichiloto-equipment-icons-' . bin2hex(random_bytes(5));
  mkdir($this->root);
});

afterEach(function () {
  foreach ($this->savedConfig as $name => $value) { new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value); }
  foreach (glob($this->root . '/*') as $path) { unlink($path); }
  rmdir($this->root);
});

it('gives all supported equipment families and slots one canonical role and terminal symbol', function () {
  $types = [...WeaponType::cases(), ...EquipmentSlotType::cases()];
  $roles = [];
  foreach ($types as $type) {
    $role = EquipmentIcon::getRole($type);
    $glyph = EquipmentIcon::getTerminalGlyph($type);
    expect(EquipmentIcon::resolveType($type))->toBe($type)
      ->and(EquipmentIcon::resolveType($role))->toBe($type)
      ->and(preg_match('/^(weapon|slot)\.[a-z-]+$/', $role))->toBe(1)
      ->and(TerminalText::displayWidth($glyph))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);
    $roles[] = $role;
  }
  expect(array_unique($roles))->toHaveCount(count($types))
    ->and(EquipmentIcon::resolveType('item.special-sword'))->toBeNull()
    ->and(EquipmentIcon::resolveType('Sword'))->toBeNull()
    ->and(EquipmentIcon::resolveType(null))->toBeNull();
});

it('removes per-item weapon icon overrides independently of names materials and prices', function (WeaponType $type) {
  $first = Weapon::fromArray(['id' => 'weapon.first', 'name' => 'First', 'description' => '', 'icon' => 'OLD-A',
    'type' => $type->value, 'material' => 'wood', 'price' => 5]);
  $second = new Weapon('Unrelated name', '', 'OLD-B', 500, equipmentType: $type, material: 'steel', id: 'weapon.second');
  foreach ([$first, $second, clone $first] as $gear) {
    expect(EquipmentIcon::getType($gear))->toBe($type)
      ->and($gear->icon)->toBe(EquipmentIcon::getTerminalGlyph($type))
      ->and(EquipmentIcon::getItemLabel($gear))->toBe($gear->icon . ' ' . $gear->name);
  }
  expect($first->price)->toBe(5)->and($second->price)->toBe(500)
    ->and($first->material)->toBe('wood')->and($second->material)->toBe('steel');
})->with(array_map(fn(WeaponType $type) => [$type], WeaponType::cases()));

it('keeps shield headgear and body armor icons independent of compatibility families and individual items', function (ArmorType $type) {
  foreach ([EquipmentSlotType::SHIELD, EquipmentSlotType::HEAD, EquipmentSlotType::BODY] as $slot) {
    $armor = Armor::fromArray(['id' => 'armor.' . $slot->value, 'name' => 'Synthetic armor', 'description' => '',
      'icon' => 'OLD-' . $slot->value, 'type' => $type->value, 'slot' => $slot->value]);
    expect(EquipmentIcon::getType($armor))->toBe($slot)
      ->and($armor->icon)->toBe(EquipmentIcon::getTerminalGlyph($slot))
      ->and($armor->semanticSlot)->toBe($slot);
  }
})->with(array_map(fn(ArmorType $type) => [$type], ArmorType::cases()));

it('uses semantic identity for untyped legacy gear without inferring type from its name or glyph', function () {
  $weapon = new Weapon('Staff of misleading names', '', '|', 5);
  $armor = new Armor('Sword-shaped decoration', '', '/', 5, semanticSlot: EquipmentSlotType::HEAD);
  $item = new Item('Consumable', '', 'AUTHORED', 5);
  expect(EquipmentIcon::getType($weapon))->toBe(EquipmentSlotType::WEAPON)
    ->and(EquipmentIcon::getType($armor))->toBe(EquipmentSlotType::HEAD)
    ->and($item->icon)->toBe('AUTHORED')->and(EquipmentIcon::getItemLabel($item))->toBe('Consumable');
});

it('reconstructs saved gear icons from current type definitions without persisting presentation state', function () {
  $old = new Weapon('Before', '', 'OLD-A', 5, quantity: 3, equipmentType: WeaponType::SWORD, id: 'weapon.changeable');
  $saved = serialize($old);
  expect($old->__serialize())->not->toHaveKey('icon')->not->toHaveKey('equipmentType');
  $current = new Weapon('After', '', 'OLD-B', 25, equipmentType: WeaponType::STAFF, id: $old->id);
  $store = new ReflectionClass(ItemStore::class)->newInstanceWithoutConstructor();
  $store->set($current->id, $current);
  ConfigStore::put(ItemStore::class, $store);
  $restored = unserialize($saved);
  expect($restored)->toBeInstanceOf(Weapon::class)->and($restored->quantity)->toBe(3)
    ->and($restored->equipmentType)->toBe(WeaponType::STAFF)->and($restored->name)->toBe('After')
    ->and($restored->icon)->toBe(EquipmentIcon::getTerminalGlyph(WeaponType::STAFF))
    ->and($current->quantity)->toBe(1)->and($restored)->not->toBe($current);
});

it('shares the exact type artwork and symbol fallback without freezing image bytes or dimensions', function () {
  writeTestPng($this->root . '/Unknown.png', 8, 8);
  $layout = new MenuRowLayout(new CanvasRectangle(0, 0, 400, 80));
  foreach ([...WeaponType::cases(), ...EquipmentSlotType::cases()] as $type) {
    $role = EquipmentIcon::getRole($type);
    $path = $role . '.png';
    $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'icons' => [
      $role => $path, 'unknown' => 'Unknown.png']]);
    $row = new MenuRow('gear', 'Name', icon: $type);
    expect($theme->icons->asset($type))->toBeNull()->and(MenuRowPainter::canShowIcon($row, $theme->icons))->toBeTrue();
    $fallback = MenuRowPainter::compose(400, 80, 'test', [$row], $layout, $theme->rows, $theme->icons);
    $layers = array_column($fallback->textLayers, null, 'id');
    expect($layers['test-gear-icon']->runs[0]->text)->toBe(EquipmentIcon::getTerminalGlyph($type));
    foreach ([8, 32] as $size) {
      writeTestPng($this->root . '/' . $path, $size, $size);
      expect($theme->icons->asset($type))->toBe($path)->and($theme->icons->asset($role))->toBe($path);
      $art = MenuRowPainter::compose(400, 80, 'test', [$row], $layout, $theme->rows, $theme->icons);
      expect(array_column($art->images, 'asset'))->toContain($path)->not->toContain('Unknown.png')
        ->and(array_column($art->textLayers, 'id'))->not->toContain('test-gear-icon');
    }
    unlink($this->root . '/' . $path);
    expect($theme->icons->asset($type))->toBeNull();
  }
});
