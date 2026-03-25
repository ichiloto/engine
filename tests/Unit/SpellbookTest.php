<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\LearnableSpell;
use Ichiloto\Engine\Entities\Magic\SpellLearningRequirement;
use Ichiloto\Engine\Entities\Magic\SpellSortOrder;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;

it('sorts learned spells in ascending and descending order', function () {
  $alpha = new MagicSkill('Alpha', 'First spell.', 'A', 1, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS);
  $flare = new MagicSkill('Flare', 'Last spell.', 'F', 3, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $cure = new MagicSkill('Cure', 'Middle spell.', 'C', 2, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::MENU_SCREEN);

  $spellbook = new Spellbook([$flare, $cure, $alpha]);

  expect(array_map(static fn(MagicSkill $spell): string => $spell->name, $spellbook->getLearnedSpells()))
    ->toBe(['Alpha', 'Cure', 'Flare']);

  $spellbook->sortLearnedSpells(SpellSortOrder::Z_TO_A);

  expect(array_map(static fn(MagicSkill $spell): string => $spell->name, $spellbook->getLearnedSpells()))
    ->toBe(['Flare', 'Cure', 'Alpha']);
});

it('learns a ready spell and consumes shared costs', function () {
  $character = new Character('Liora', 800000, new Stats(currentMp: 20, totalMp: 20));
  $party = new Party();
  $party->accountBalance = 300;
  $party->inventory->addItems(new Item('S-Potion', 'Restores HP.', '!', 10, 3));

  $warp = new MagicSkill('Warp', 'Travel magic.', 'W', 8, 0, new ItemScope(ItemScopeSide::USER), Occasion::MENU_SCREEN);
  $learnable = new LearnableSpell(
    $warp,
    new SpellLearningRequirement(
      experienceRequired: 500000,
      trainingHoursRequired: 2,
      goldCost: 120,
      itemCosts: ['S-Potion' => 2],
    ),
    trainingHours: 2,
  );

  $spellbook = new Spellbook([], [$learnable]);

  expect($spellbook->learn($learnable, $character, $party))->toBeTrue()
    ->and(array_map(static fn(MagicSkill $spell): string => $spell->name, $spellbook->getLearnedSpells()))
    ->toContain('Warp')
    ->and($party->accountBalance)->toBe(180)
    ->and($party->inventory->getQuantityByName('S-Potion'))->toBe(1);
});

it('filters field-usable spells from the learned list', function () {
  $fieldSpell = new MagicSkill('Cure', 'Field spell.', 'C', 3, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::MENU_SCREEN);
  $battleSpell = new MagicSkill('Fire', 'Battle spell.', 'F', 4, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $alwaysSpell = new MagicSkill('Regen', 'Always usable.', 'R', 5, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS);

  $spellbook = new Spellbook([$fieldSpell, $battleSpell, $alwaysSpell]);

  expect(array_map(static fn(MagicSkill $spell): string => $spell->name, $spellbook->getFieldUsableSpells()))
    ->toBe(['Cure', 'Regen']);
});
