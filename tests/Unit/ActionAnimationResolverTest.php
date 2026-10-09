<?php

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
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

it('uses authored basic weapons without granting equipment and lets equipped weapons override them', function (WeaponType $type) {
  $actor = new Character('Any actor', 1, new Stats(), attackStyle: $type);
  $before = $actor->stats->jsonSerialize();
  $baseRole = 'attack-' . strtolower($type->value);
  expect(ActionAnimationResolver::getAttackRole($actor))->toBe($baseRole)
    ->and(array_all($actor->equipment, static fn($slot): bool => $slot->equipment === null))->toBeTrue();
  $override = $type === WeaponType::STAFF ? WeaponType::SWORD : WeaponType::STAFF;
  expect($actor->assignEquipment('Weapon', new Weapon('Arbitrary name', '', '', 0, equipmentType: $override)))->toBeTrue()
    ->and(ActionAnimationResolver::getAttackRole($actor))->toBe('attack-' . strtolower($override->value));
  expect($actor->assignEquipment('Weapon', new Weapon('Unclassified', '', '', 0)))->toBeTrue()
    ->and(ActionAnimationResolver::getAttackRole($actor))->toBe('attack');
  expect($actor->assignEquipment('Weapon', null))->toBeTrue()
    ->and(ActionAnimationResolver::getAttackRole($actor))->toBe($baseRole)
    ->and($actor->stats->jsonSerialize())->toBe($before);
})->with(WeaponType::cases());

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

it('shares actual action selection with previews without role or name fallbacks for explicit IDs', function () {
  $actor = new Character('Caster', 1, new Stats(currentHp: 100, totalHp: 100, currentMp: 40), attackStyle: WeaponType::SWORD);
  $blade = new Animation(1, 'Not a sword', targetEffect: 'blade-impact', roles: ['attack-sword']);
  $magic = new Animation(2, 'Not a spell', sourceEffect: 'cast', targetEffect: 'magic-impact', roles: ['skill']);
  $heal = new Animation(3, 'Not healing', targetEffect: 'restore', roles: ['restorative']);
  $explicit = new Animation(4, 'Any authored choice', sourceEffect: 'prepare', targetEffect: 'special-impact');
  $library = AnimationLibrary::createFromAnimations([$blade, $magic, $heal, $explicit]);
  $attack = new AttackAction('Attack');
  $basic = new SkillBattleAction(new BasicSkill('Natural strike', '', '', 0, 0));
  $spell = new SkillBattleAction(new MagicSkill('Spell', '', '', 4, 0, effectType: MagicEffectType::DESTRUCTIVE));
  $recovery = new SkillBattleAction(new MagicSkill('Recovery', '', '', 4, 0, effectType: MagicEffectType::RESTORATIVE));
  $choice = new SkillBattleAction(new BasicSkill('Authored strike', '', '', 0, 0, animationId: 4));
  $broken = new SkillBattleAction(new BasicSkill('Missing strike', '', '', 0, 0, animationId: 999));
  $item = new Item('Potion', '', '', 1, animationId: 4);
  expect(ActionAnimationResolver::resolveForAction($attack, $actor, $library))->toBe($blade)
    ->and(ActionAnimationResolver::resolveForAction($basic, $actor, $library))->toBe($blade)
    ->and(ActionAnimationResolver::resolveForAction($spell, $actor, $library))->toBe($magic)
    ->and(ActionAnimationResolver::resolveForAction($recovery, $actor, $library))->toBe($heal)
    ->and(ActionAnimationResolver::resolveForAction($choice, $actor, $library))->toBe($explicit)
    ->and(ActionAnimationResolver::resolveForAction($broken, $actor, $library))->toBeNull()
    ->and(ActionAnimationResolver::resolveForAction(new ItemBattleAction($item), $actor, $library))->toBe($explicit)
    ->and(ActionAnimationResolver::resolveForAction(new ItemBattleAction(new Item('Blank', '', '', 1)), $actor, $library))->toBeNull()
    ->and(ActionAnimationResolver::resolveForAction(new GuardAction('Guard'), $actor, $library))->toBeNull()
    ->and(ActionAnimationResolver::resolveForAction(null, $actor, $library))->toBeNull()
    ->and($actor->stats->currentMp)->toBe(40)->and($item->quantity)->toBe(1);
});

it('finds both effect lanes through real command bindings and keeps contextual action poses', function () {
  $actor = new Character('Any caster', 1, new Stats(currentHp: 100, totalHp: 100), attackStyle: WeaponType::DAGGER);
  $art = new Animation(1, 'Replaceable', sourceEffect: 'shared', targetEffect: 'shared', roles: ['attack-dagger', 'restorative']);
  $unbound = new Animation(2, 'No consumer', targetEffect: 'unused');
  $library = AnimationLibrary::createFromAnimations([$art, $unbound]);
  $attack = new AttackAction('Attack');
  $magic = new SkillBattleAction(new MagicSkill('Spell', '', '', 2, 0, effectType: MagicEffectType::RESTORATIVE));
  $item = new ItemBattleAction(new Item('Tool', '', '', 1, animationId: 1));
  $bindings = ActionAnimationResolver::findEffectBindings('shared', $library, $actor, [$attack, $magic, $item]);
  expect(array_column($bindings, 'lane'))->toBe(['source', 'target', 'source', 'target', 'source', 'target'])
    ->and(array_column($bindings, 'action'))->toBe([$attack, $attack, $magic, $magic, $item, $item])
    ->and(array_all($bindings, static fn($binding): bool => $binding['animation'] === $art))->toBeTrue()
    ->and(array_map(static fn($binding) => BattlePoseRole::getForAction($binding['action']), $bindings))
    ->toBe([BattlePoseRole::ATTACK, BattlePoseRole::ATTACK, BattlePoseRole::MAGIC, BattlePoseRole::MAGIC, BattlePoseRole::ITEM, BattlePoseRole::ITEM])
    ->and(ActionAnimationResolver::findEffectBindings('unused', $library, $actor, [$attack]))->toBeEmpty()
    ->and(ActionAnimationResolver::findEffectBindings('shared', $library,
      new Character('Staff user', 1, new Stats(), attackStyle: WeaponType::STAFF), [$attack]))->toBeEmpty();
});

it('refuses ambiguous role bindings and invalid snapshots instead of selecting the first preview match', function () {
  $actor = new Character('Caster', 1, new Stats(), attackStyle: WeaponType::SWORD);
  $first = new Animation(1, 'First', roles: ['attack-sword']);
  $second = new Animation(2, 'Second', roles: ['attack-sword']);
  $library = AnimationLibrary::createFromAnimations([$first, $second]);
  expect(ActionAnimationResolver::resolveForAction(new AttackAction('Attack'), $actor, $library))->toBeNull()
    ->and(fn() => AnimationLibrary::createFromAnimations([new stdClass()]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => AnimationLibrary::createFromAnimations(['named' => $first]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => ActionAnimationResolver::findEffectBindings('../unsafe', $library, $actor, []))->toThrow(InvalidArgumentException::class);
});
