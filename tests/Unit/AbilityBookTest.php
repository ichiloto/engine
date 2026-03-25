<?php

use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Abilities\AbilityLearningRequirement;
use Ichiloto\Engine\Entities\Abilities\AbilitySortOrder;
use Ichiloto\Engine\Entities\Abilities\LearnableAbility;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

it('sorts learned abilities in ascending and descending order', function () {
  $aegis = new SpecialSkill('Aegis Cry', 'Guard allies.', 'A', 5, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $judgment = new SpecialSkill('Judgment Blade', 'Strike one foe.', 'J', 7, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $radiant = new SpecialSkill('Radiant Slash', 'Cut one foe.', 'R', 3, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);

  $abilityBook = new AbilityBook([$radiant, $judgment, $aegis]);

  expect(array_map(static fn(SpecialSkill $ability): string => $ability->name, $abilityBook->getLearnedAbilities()))
    ->toBe(['Aegis Cry', 'Judgment Blade', 'Radiant Slash']);

  $abilityBook->sortLearnedAbilities(AbilitySortOrder::Z_TO_A);

  expect(array_map(static fn(SpecialSkill $ability): string => $ability->name, $abilityBook->getLearnedAbilities()))
    ->toBe(['Radiant Slash', 'Judgment Blade', 'Aegis Cry']);
});

it('learns a ready ability and consumes mixed unlock costs', function () {
  $character = new Character('Kaelion', 800000, new Stats(currentMp: 12, totalMp: 12));
  $party = new Party();
  $party->accountBalance = 300;
  $party->inventory->addItems(new Item('S-Potion', 'Restores HP.', '!', 10, 3));

  $ability = new SpecialSkill(
    'Judgment Blade',
    'Strike one foe.',
    'J',
    7,
    0,
    new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE),
    Occasion::BATTLE_SCREEN
  );
  $learnable = new LearnableAbility(
    $ability,
    new AbilityLearningRequirement(
      experienceRequired: 500000,
      playTimeSecondsRequired: 600,
      goldCost: 120,
      itemCosts: ['S-Potion' => 2],
      requiredEvents: ['sunsteel_trial'],
    ),
  );

  $abilityBook = new AbilityBook([], [$learnable]);

  expect($abilityBook->learn($learnable, $character, $party, ['sunsteel_trial'], 600))->toBeTrue()
    ->and(array_map(static fn(SpecialSkill $skill): string => $skill->name, $abilityBook->getLearnedAbilities()))
    ->toContain('Judgment Blade')
    ->and($party->accountBalance)->toBe(180)
    ->and($party->inventory->getQuantityByName('S-Potion'))->toBe(1);
});

it('filters battle-usable abilities from the learned list', function () {
  $battleAbility = new SpecialSkill('Radiant Slash', 'Battle-only.', 'R', 3, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $alwaysAbility = new SpecialSkill('Guardian Vow', 'Always available.', 'G', 4, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS);
  $fieldAbility = new SpecialSkill('Map Scan', 'Field-only.', 'M', 1, 0, new ItemScope(ItemScopeSide::USER), Occasion::MENU_SCREEN);

  $abilityBook = new AbilityBook([$battleAbility, $alwaysAbility, $fieldAbility]);

  expect(array_map(static fn(SpecialSkill $ability): string => $ability->name, $abilityBook->getBattleUsableAbilities()))
    ->toBe(['Guardian Vow', 'Radiant Slash']);
});
