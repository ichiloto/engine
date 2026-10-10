<?php

use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\ModifyStatStageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RemoveStateSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SkillResolutionScope;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/** A record's data with only what every skill states. */
function minimalSkillData(array $overrides = []): array
{
  return [
    'kind' => 'special',
    'name' => 'Lunge',
    'description' => 'Strikes far.',
    'icon' => '',
    'cost' => 4,
    'cooldown' => 1,
    'occasion' => 'Battle Screen',
    'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '$1 lunges at $2!', 'speed' => 0, 'accuracy' => 90, 'repeat' => 1, 'apGain' => 10],
    'effects' => [],
    ...$overrides,
  ];
}

it('round-trips every kind and every effect type through record data', function () {
  $effects = [
    new HPDamageSkillEffect('$user->stats->attack * 4', 'Fire', 0.15, true, ResolutionKind::MAGICAL_DAMAGE),
    new HPDrainSkillEffect('$user->stats->magicAttack * 2'),
    new HPRecoverSkillEffect('$user->stats->magicAttack * 3', null, 0.1),
    new MPDamageSkillEffect('5'),
    new MPDrainSkillEffect('3'),
    new MPRecoverySkillEffect('10', null, 0.0),
    new AddStateSkillEffect('poison', 85),
    new RemoveStateSkillEffect(['poison', 'stun']),
    new ModifyStatStageSkillEffect('defence', 2, true),
  ];
  $skills = [
    new BasicSkill('Attack', 'Strikes.', 'ATK', 0, 0, new ItemScope(), Occasion::BATTLE_SCREEN, new SkillInvocation('$1 attacks $2!', 0, 0, 1, 10)),
    new SpecialSkill('Flurry', 'Many blows.', 'FLR', 6, 2, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::RANDOM, ItemScopeStatus::ALIVE, 3),
      Occasion::BATTLE_SCREEN, new SkillInvocation('$1 flurries!', 5, 80, 3, 12, SkillResolutionScope::PER_ACTION, SkillResolutionScope::PER_TARGET),
      $effects, animationId: 4),
    new MagicSkill('Mend', 'Heals.', '', 3, 0, new ItemScope(ItemScopeSide::ALLY), Occasion::ALWAYS, new SkillInvocation(),
      [new HPRecoverSkillEffect('20')], effectType: MagicEffectType::BUFF),
  ];

  foreach ($skills as $skill) {
    $data = SkillRecord::writeSkill($skill);

    expect(SkillRecord::writeSkill(SkillRecord::readSkill($data)))->toBe($data)
      ->and(SkillRecord::readSkill($data))->toEqual($skill);
  }

  expect(SkillRecord::writeSkill($skills[1])['effects'])->toBe([
    ['type' => 'hp_damage', 'formula' => '$user->stats->attack * 4', 'element' => 'Fire', 'variance' => 0.15, 'isCriticalHit' => true, 'resolutionKind' => 'magicalDamage'],
    ['type' => 'hp_drain', 'formula' => '$user->stats->magicAttack * 2', 'variance' => 0.2],
    ['type' => 'hp_recover', 'formula' => '$user->stats->magicAttack * 3', 'variance' => 0.1],
    ['type' => 'mp_damage', 'formula' => '5', 'variance' => 0.2],
    ['type' => 'mp_drain', 'formula' => '3', 'variance' => 0.2],
    ['type' => 'mp_recover', 'formula' => '10', 'variance' => 0.0],
    ['type' => 'add_state', 'stateId' => 'poison', 'chancePercent' => 85],
    ['type' => 'remove_state', 'stateIds' => ['poison', 'stun']],
    ['type' => 'modify_stat_stage', 'stat' => 'defence', 'delta' => 2, 'affectsUser' => true],
  ]);
});

it('states only what an author chose, and a spell always its effect type', function () {
  $attack = SkillRecord::writeSkill(new BasicSkill('Attack', 'Strikes.', '', 0, 0));
  $inferred = SkillRecord::writeSkill(new MagicSkill('Mend', 'Heals.', '', 3, 0, effects: [new HPRecoverSkillEffect('20')]));

  expect($attack)->not->toHaveKeys(['animationId', 'effectType'])
    ->and($attack['scope'])->toBe(['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'])
    ->and($attack['invocation'])->toBe(['message' => '$1 casts $2!', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 10])
    ->and($inferred['effectType'])->toBe('restorative')
    ->and(SkillRecord::writeSkill(new SpecialSkill('Ward', 'Guards.', '', 2, 0, effects: [new AddStateSkillEffect('guard')]))['effects'])
    ->toBe([['type' => 'add_state', 'stateId' => 'guard']]);
});

it('refuses data that is not a skill record, saying where', function (array $data, string $message) {
  expect(fn() => SkillRecord::readSkill($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'unknown key' => [minimalSkillData(['power' => 3]), 'Unknown skill record key "power".'],
  'unknown kind' => [minimalSkillData(['kind' => 'summon']), 'kind "summon" is not one of basic, special, magic.'],
  'missing name' => [minimalSkillData(['name' => ' ']), 'name is required text.'],
  'spell type on an ability' => [minimalSkillData(['effectType' => 'buff']), 'effectType belongs to a spell; this skill is not one.'],
  'bad occasion' => [minimalSkillData(['occasion' => 'Sometimes']), 'occasion "Sometimes" is not one of Always, Battle Screen, Menu Screen, Never.'],
  'missing scope part' => [minimalSkillData(['scope' => ['side' => 'Enemy', 'number' => 'One']]), 'scope.status is required text.'],
  'unknown effect' => [minimalSkillData(['effects' => [['type' => 'teleport']]]), 'effects.0.type "teleport" is not one of'],
  'effect key of another type' => [minimalSkillData(['effects' => [['type' => 'hp_recover', 'formula' => '5', 'resolutionKind' => 'healing']]]), 'Unknown key "effects.0.resolutionKind" for a hp_recover effect.'],
  'unknown stat' => [minimalSkillData(['effects' => [['type' => 'modify_stat_stage', 'stat' => 'luck', 'delta' => 1]]]), 'effects.0.stat "luck" is not one of'],
  'no states to remove' => [minimalSkillData(['effects' => [['type' => 'remove_state', 'stateIds' => []]]]), 'effects.0.stateIds must list the states it removes.'],
]);

it('refuses to write what a record cannot name', function () {
  $weapon = new Ichiloto\Engine\Entities\Inventory\Weapons\Weapon('Blade', 'Sharp.', '', 0);

  expect(fn() => SkillRecord::writeSkill(new SpecialSkill('Riposte', 'Counters.', '', 0, 0, requiredWeapons: [$weapon])))
    ->toThrow(InvalidArgumentException::class, 'Riposte requires weapons, which a skill record cannot name yet.');
});
