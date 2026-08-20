<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\SkillToLearn;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progression\ExperienceAwarder;

function progressionTestCharacter(string $name): Character
{
  $character = new Character($name, 0, new Stats(currentHp: 100, currentMp: 30));
  $ability = new SpecialSkill('Role Ability', '', '', 0, 0);
  $magic = new MagicSkill('Role Magic', '', '', 0, 0);
  $role = new CharacterRole(
    $character,
    'Tester',
    new ExperienceCurveGenerator(),
    [
      new SkillToLearn(2, $ability),
      new SkillToLearn(4, $magic),
    ],
  );

  new ReflectionProperty(Character::class, 'role')->setValue($character, $role);
  new ReflectionMethod(Character::class, 'calculateLevelExpThresholds')->invoke($character);

  return $character;
}

it('grants every crossed role skill and reports magic separately', function () {
  $character = progressionTestCharacter('Kaelion');
  $character->stats->currentHp = 1;
  $character->stats->currentMp = 1;
  $result = ExperienceAwarder::award($character, 10000);

  expect($result->oldLevel)->toBe(1)
    ->and($result->newLevel)->toBeGreaterThanOrEqual(4)
    ->and($character->stats->currentHp)->toBe($character->stats->totalHp)
    ->and($character->stats->currentMp)->toBe($character->stats->totalMp)
    ->and($result->learnedAbilities)->toBe(['Role Ability'])
    ->and($result->learnedMagic)->toBe(['Role Magic'])
    ->and(array_column($character->abilityBook->getLearnedAbilities(), 'name'))->toContain('Role Ability')
    ->and(array_column($character->spellbook->getLearnedSpells(), 'name'))->toContain('Role Magic');
});

it('preserves current resources when an experience award does not level up', function () {
  $character = progressionTestCharacter('Kaelion');
  $character->stats->currentHp = 7;
  $character->stats->currentMp = 3;

  $result = ExperienceAwarder::award($character, 1);

  expect($result->levelledUp())->toBeFalse()
    ->and($character->stats->currentHp)->toBe(7)
    ->and($character->stats->currentMp)->toBe(3);
});

it('awards the full travelling roster and reconciles idempotently', function () {
  $party = new Party();

  foreach (['A', 'B', 'C', 'Reserve'] as $name) {
    $character = progressionTestCharacter($name);
    $character->stats->currentHp = 1;
    $character->stats->currentMp = 0;
    $party->addMember($character);
  }

  $results = ExperienceAwarder::awardParty($party, 10000);
  $secondPass = array_map(
    ExperienceAwarder::reconcileAutomaticRoleSkills(...),
    $party->members->toArray(),
  );

  expect($results)->toHaveCount(4)
    ->and(array_map(static fn($result): array => $result->learnedSkills(), $results))
    ->each->toBe(['Role Ability', 'Role Magic'])
    ->and(array_map(static fn($result): array => $result->learnedSkills(), $secondPass))
    ->each->toBe([])
    ->and(array_map(
      static fn(Character $character): bool =>
        $character->stats->currentHp === $character->stats->totalHp
        && $character->stats->currentMp === $character->stats->totalMp,
      $party->members->toArray(),
    ))->each->toBeTrue();
});
