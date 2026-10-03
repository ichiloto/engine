<?php

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Stats;

it('selects attack roles from current equipment rather than names, costumes or party identity', function (WeaponType $type) {
  $actor = new Character('Renamed actor', 1, new Stats());
  $weapon = new Weapon('Misleading sword name', '', '', 1, equipmentType: $type);
  $slots = $actor->equipment;
  $slots[0]->equipment = $weapon;
  $role = 'attack-' . strtolower($type->value);
  expect(ActionAnimationResolver::getAttackRole($actor))->toBe($role)
    ->and(new Animation(1, 'Unrelated name', roles: [$role])->toArray()['roles'])->toBe([$role]);
  $slots[0]->equipment = null;
  expect(ActionAnimationResolver::getAttackRole($actor))->toBe('attack-unarmed');
})->with(WeaponType::cases());

it('keeps unclassified and natural attacks neutral rather than assuming a blade', function () {
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  $actor = new Character('Actor', 1, new Stats());
  $slots = $actor->equipment;
  $slots[0]->equipment = new Weapon('Unclassified', '', '', 1);
  expect(ActionAnimationResolver::getAttackRole($enemy))->toBe('attack')
    ->and(ActionAnimationResolver::getAttackRole($actor))->toBe('attack');
});

it('exposes one closed role list shared by authoring and animation validation', function () {
  $roles = ActionAnimationResolver::getSupportedRoles();
  expect($roles)->toBe(['attack', 'skill', 'restorative', 'attack-unarmed',
    ...array_map(static fn(WeaponType $type): string => 'attack-' . strtolower($type->value), WeaponType::cases())])
    ->and($roles)->toBe(array_values(array_unique($roles)));
  foreach ($roles as $role) {
    expect(Animation::fromArray(['id' => 1, 'name' => 'Binding', 'roles' => [$role]])->toArray()['roles'])->toBe([$role]);
  }
  expect(fn() => new Animation(1, 'Invalid binding', roles: ['attack-not-a-weapon']))
    ->toThrow(InvalidArgumentException::class, 'a supported attack weapon role');
});
