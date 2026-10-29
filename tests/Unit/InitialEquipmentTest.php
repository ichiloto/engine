<?php

use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

beforeEach(function () {
  $this->previousItems = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;
  $this->items = (new ReflectionClass(ItemStore::class))->newInstanceWithoutConstructor();
  $this->items->set('equipment.test-bow', new Weapon('Test Bow', '', '', 100,
    parameterChanges: new ParameterChanges(attack: 2), equipmentType: WeaponType::BOW, id: 'equipment.test-bow'));
  $this->items->set('item.tonic', new Item('Tonic', '', '', 10, id: 'item.tonic'));
  ConfigStore::put(ItemStore::class, $this->items);
  $this->data = ['id' => 'actor.archer', 'name' => 'Archer', 'currentExp' => 0,
    'stats' => new Stats()->jsonSerialize(), 'initialEquipment' => ['weapon' => 'equipment.test-bow']];
});

afterEach(function () {
  $this->previousItems === null ? ConfigStore::remove(ItemStore::class) : ConfigStore::put(ItemStore::class, $this->previousItems);
});

it('equips independent catalogue instances for new actors and resolves current item identity', function () {
  $definition = ActorDefinition::fromArray($this->data);
  $first = $definition->createCharacter();
  $second = $definition->createCharacter();
  expect($first->equipment[0]->equipment->id)->toBe('equipment.test-bow')
    ->and($first->equipment[0]->equipment)->not->toBe($second->equipment[0]->equipment)
    ->and($first->effectiveStats->totalAttack)->toBe($second->effectiveStats->totalAttack);
  $this->items->set('equipment.test-bow', new Weapon('Renamed Bow', '', '', 100,
    equipmentType: WeaponType::BOW, id: 'equipment.test-bow'));
  expect($definition->createCharacter()->equipment[0]->equipment->name)->toBe('Renamed Bow');
});

it('preserves unequipped and replaced saved gear when current starting gear changes or disappears', function () {
  $definition = ActorDefinition::fromArray($this->data);
  $actor = $definition->createCharacter();
  $actor->assignEquipment('Weapon', null);
  $saved = $actor->toArray();
  $changed = ActorDefinition::fromArray([...$this->data, 'initialEquipment' => ['weapon' => 'equipment.removed']]);
  expect($changed->createCharacter($saved)->equipment[0]->equipment)->toBeNull();
  $upgrade = new Weapon('Owned Upgrade', '', '', 100, equipmentType: WeaponType::BOW);
  $this->items->set($upgrade->id, $upgrade);
  $actor->assignEquipment('Weapon', $this->items->get($upgrade->id));
  expect($changed->createCharacter($actor->toArray())->equipment[0]->equipment->name)->toBe('Owned Upgrade');
  $party = new Party();
  $party->addMember($actor);
  $loaded = unserialize(serialize($party));
  expect($loaded->recruitActor($changed))->toBeFalse()
    ->and($loaded->leader->equipment[0]->equipment->name)->toBe('Owned Upgrade');
});

it('refuses wrong slots, consumables, unknown references and class-incompatible equipment before recruitment', function () {
  $party = new Party();
  foreach ([['head' => 'equipment.test-bow'], ['weapon' => 'item.tonic'], ['weapon' => 'missing']] as $loadout) {
    expect(fn() => $party->recruitActor(ActorDefinition::fromArray([...$this->data, 'initialEquipment' => $loadout])))
      ->toThrow(\Exception::class);
  }
  $seed = new Character('Role seed', 0, new Stats());
  $role = new CharacterRole($seed, 'Staff user', allowedWeaponTypes: [WeaponType::STAFF]);
  expect(fn() => $party->recruitActor(ActorDefinition::fromArray([...$this->data, 'role' => $role])))
    ->toThrow(InvalidArgumentException::class, 'cannot equip')
    ->and($party->members->count())->toBe(0);
});

it('rejects malformed loadout shapes while absent loadouts need no item catalogue', function () {
  foreach (['bow', ['hat' => 'equipment.test-bow'], ['weapon' => ''], ['weapon' => []]] as $invalid) {
    expect(fn() => ActorDefinition::fromArray([...$this->data, 'initialEquipment' => $invalid]))
      ->toThrow(InvalidArgumentException::class);
  }
  ConfigStore::remove(ItemStore::class);
  unset($this->data['initialEquipment']);
  expect(ActorDefinition::fromArray($this->data)->createCharacter()->equipment[0]->equipment)->toBeNull();
});
