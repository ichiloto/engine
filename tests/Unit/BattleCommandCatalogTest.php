<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Core\GameState;
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

/** Runs a catalog assertion inside a disposable project with one summon. */
function withCatalogSummonProject(?array $availability, ?array $wielders, callable $assertion): void
{
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-command-summon-' . uniqid();
  $directory = $root . '/assets/Cutscenes/Summons/test-summon';
  mkdir($directory, 0777, true);
  mkdir($root . '/assets/Data', 0777, true);

  $data = [
    'id' => 'test-summon',
    'name' => 'Test Summon',
    'linkedActionId' => 'Test Summon Action',
  ];

  if ($availability !== null) {
    $data['availability'] = $availability;
  }

  if ($wielders !== null) {
    $data['wielders'] = $wielders;
  }

  file_put_contents(
    $directory . '/test-summon.data.php',
    "<?php\n\nreturn " . var_export($data, true) . ";\n",
  );
  file_put_contents(
    $directory . '/test-summon.timeline.php',
    "<?php\n\nreturn ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];\n",
  );
  file_put_contents($root . '/assets/Data/skills.php', <<<'PHP'
<?php

use Ichiloto\Engine\Entities\Skills\SpecialSkill;

return [new SpecialSkill('Test Summon Action', 'Test action.', '', 4, 0)];
PHP);

  try {
    chdir($root);
    $assertion();
  } finally {
    chdir($previous);
    unlink($directory . '/test-summon.data.php');
    unlink($directory . '/test-summon.timeline.php');
    unlink($root . '/assets/Data/skills.php');
    rmdir($directory);
    rmdir(dirname($directory));
    rmdir(dirname(dirname($directory)));
    rmdir($root . '/assets/Data');
    rmdir($root . '/assets');
    rmdir($root);
  }
}

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
  $potion = new Item('Potion', 'Restore HP.', '!', 10, 3, occasion: Occasion::ALWAYS);
  $party->inventory->addItems(
    $potion,
    new Item('Tent', 'Field-only rest.', 'T', 100, 1, occasion: Occasion::MENU_SCREEN),
  );

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Item', [$potion->id => 1]);

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Potion'])
    ->and($options[0]->label)->toContain('x2');
});

it('preserves open summon commands for generic projects', function () {
  withCatalogSummonProject(null, null, function (): void {
    $character = new Character('Anyone', 0, new Stats());
    $party = new Party();
    $party->addMember($character);

    $commands = BattleCommandCatalog::buildCommands($character, $party, new GameState());
    $labels = array_map(static fn($command): string => $command->name, $commands);

    expect($labels)->toContain(BattleCommandType::SUMMON->label())
      ->and(BattleCommandCatalog::buildOptions($character, $party, 'Summon', [], new GameState()))->toHaveCount(1);
  });
});

it('hides locked summons and exposes them only to their assigned holder', function () {
  withCatalogSummonProject(
    ['conditions' => [['type' => 'event', 'name' => 'summon_unlocked']]],
    ['mode' => 'characters', 'characters' => ['Holder', 'Other'], 'tenancy' => 'exclusive'],
    function (): void {
      $holder = new Character('Holder', 0, new Stats());
      $other = new Character('Other', 0, new Stats());
      $party = new Party();
      $party->addMember($holder);
      $party->addMember($other);
      $holder->assignSummon('test-summon');
      $state = new GameState();

      expect(array_map(
        static fn($command): string => $command->name,
        BattleCommandCatalog::buildCommands($holder, $party, $state),
      ))->not->toContain(BattleCommandType::SUMMON->label());

      $state->recordStoryEvent('summon_unlocked');

      expect(BattleCommandCatalog::buildOptions($holder, $party, 'Summon', [], $state))->toHaveCount(1)
        ->and(BattleCommandCatalog::buildOptions($other, $party, 'Summon', [], $state))->toBe([])
        ->and(BattleCommandCatalog::canUseSummonAction($holder, $party, 'Test Summon Action', $state))->toBeTrue()
        ->and(BattleCommandCatalog::canUseSummonAction($other, $party, 'Test Summon Action', $state))->toBeFalse();
    },
  );
});
