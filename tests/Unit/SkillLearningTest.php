<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

/**
 * Builds a character with empty books.
 */
function makeLearner(string $name = 'Kaelion'): Character
{
  return new Character($name, 0, new Stats(
    totalHp: 500,
    totalMp: 100,
    attack: 10,
    defence: 10,
    magicAttack: 10,
    magicDefence: 10,
    speed: 10,
    grace: 10,
    evasion: 5
  ));
}

function makeSpell(string $name = 'Fire'): MagicSkill
{
  return new MagicSkill($name, 'Burns a foe.', '🔥', 5, 0);
}

function makeAbility(string $name = 'Cross Slash'): SpecialSkill
{
  return new SpecialSkill($name, 'A brutal combo.', '⚔️', 8, 0);
}

/**
 * Returns the names of the character's learned abilities.
 *
 * @return string[]
 */
function learnedAbilityNames(Character $character): array
{
  return array_map(static fn($ability): string => $ability->name, $character->abilityBook->getLearnedAbilities());
}

/**
 * Returns the names of the character's learned spells.
 *
 * @return string[]
 */
function learnedSpellNames(Character $character): array
{
  return array_map(static fn($spell): string => $spell->name, $character->spellbook->getLearnedSpells());
}

it('files a learned spell in the spellbook, not the ability book', function () {
  $character = makeLearner();

  expect($character->learnSkill(makeSpell()))->toBeTrue()
    ->and(learnedSpellNames($character))->toBe(['Fire'])
    ->and(learnedAbilityNames($character))->toBe([]);
});

it('files a learned special skill in the ability book', function () {
  $character = makeLearner();

  expect($character->learnSkill(makeAbility()))->toBeTrue()
    ->and(learnedAbilityNames($character))->toBe(['Cross Slash'])
    ->and(learnedSpellNames($character))->toBe([]);
});

it('reports an already known skill as not newly learned', function () {
  $character = makeLearner();
  $character->learnSkill(makeSpell());
  $character->learnSkill(makeAbility());

  expect($character->learnSkill(makeSpell()))->toBeFalse()
    ->and($character->learnSkill(makeAbility()))->toBeFalse()
    ->and(learnedSpellNames($character))->toBe(['Fire'])
    ->and(learnedAbilityNames($character))->toBe(['Cross Slash']);
});

it('serializes a character who has learned magic', function () {
  // The save path serializes the character; a spell misfiled as an ability
  // raised a TypeError here and crashed the game on save. Calling serialize()
  // directly means a regression fails this test outright.
  $character = makeLearner();
  $character->learnSkill(makeSpell());
  $character->learnSkill(makeAbility());

  $payload = serialize($character);

  expect($payload)->toBeString()
    ->and(strlen($payload))->toBeGreaterThan(0);
});

it('round-trips learned skills through the save payload', function () {
  $character = makeLearner();
  $character->learnSkill(makeSpell());
  $character->learnSkill(makeAbility());

  $abilityPayload = $character->abilityBook->toArray();
  $spellPayload = $character->spellbook->toArray();

  expect($abilityPayload['learned'])->toBe(['Cross Slash'])
    ->and($spellPayload['learned'])->toBe(['Fire']);
});

it('refuses to file magic in the ability book', function () {
  $character = makeLearner();

  // Misfiling is a programming error, not a silent data loss: the book
  // cannot serialize magic and would drop it on load.
  expect(fn() => $character->abilityBook->learnSkillDirectly(makeSpell()))
    ->toThrow(InvalidArgumentException::class);
});

it('refuses to add magic as a learned ability', function () {
  $character = makeLearner();

  expect(fn() => $character->abilityBook->addLearnedAbility(makeSpell()))
    ->toThrow(InvalidArgumentException::class);
});

it('serializes an ability book holding a plain skill kind', function () {
  // The book accepts any non-magic skill, so serialization must not be
  // pinned to one concrete subclass.
  $character = makeLearner();
  $character->abilityBook->addLearnedAbility(makeAbility('Focus'));

  expect($character->abilityBook->toArray()['learned'])->toBe(['Focus']);
});
