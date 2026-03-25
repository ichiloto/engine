<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

it('builds battle magic options from a character spellbook', function () {
  $cure = new MagicSkill('Cure', 'Recover HP.', 'C', 3, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS);
  $fire = new MagicSkill('Fire', 'Deal fire damage.', 'F', 4, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $warp = new MagicSkill('Warp', 'Field-only travel magic.', 'W', 8, 0, new ItemScope(ItemScopeSide::USER), Occasion::MENU_SCREEN);

  $character = new Character('Liora', 500000, new Stats(), spellbook: new Spellbook([$cure, $fire, $warp]));
  $party = new Party();

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Magic');

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Cure', 'Fire']);
});

it('builds battle skill options from a character ability book', function () {
  $radiantSlash = new SpecialSkill('Radiant Slash', 'Strike one foe.', 'R', 3, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $guardianVow = new SpecialSkill('Guardian Vow', 'Protect one ally.', 'G', 4, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);

  $character = new Character('Kaelion', 500000, new Stats(), abilityBook: new AbilityBook([$radiantSlash, $guardianVow]));
  $party = new Party();

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Skill');

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Guardian Vow', 'Radiant Slash']);
});

it('builds battle item options from the shared field inventory', function () {
  $character = new Character('Kaelion', 500000, new Stats());
  $party = new Party();
  $party->inventory->addItems(
    new Item('Potion', 'Restore HP.', '!', 10, 3, occasion: Occasion::ALWAYS),
    new Item('Tent', 'Field-only rest.', 'T', 100, 1, occasion: Occasion::MENU_SCREEN),
  );

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Item', ['Potion' => 1]);

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Potion'])
    ->and($options[0]->label)->toContain('x2');
});
