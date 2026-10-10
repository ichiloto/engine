<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Abilities\LearnableAbility;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\LearnableSpell;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

/** Runs a catalog assertion inside a disposable project with one summon. */
function withCatalogSummonProject(?array $availability, ?array $wielders, callable $assertion): void
{
  $previous = getcwd();
  $root = createTestDirectory('ichiloto-command-summon-');
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
  writeSkillRecords($root, ...[new SpecialSkill('Test Summon Action', 'Test action.', '', 4, 0)]);

  try {
    chdir($root);
    $assertion();
  } finally {
    chdir($previous);
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
  }
}

it('shares authored summon definitions during a battle and releases them afterward', function () {
  withCatalogSummonProject(null, null, function (): void {
    BattleCommandCatalog::beginBattle();
    try {
      expect(BattleCommandCatalog::isSummonActionId('Test Summon Action'))->toBeTrue();
      $path = getcwd() . '/assets/Cutscenes/Summons/test-summon/test-summon.data.php';
      file_put_contents($path, "<?php return ['id' => 'test-summon', 'name' => 'Changed', 'linkedActionId' => 'New Summon Action'];");
      expect(BattleCommandCatalog::isSummonActionId('Test Summon Action'))->toBeTrue();
    } finally { BattleCommandCatalog::endBattle(); }
    expect(BattleCommandCatalog::isSummonActionId('Test Summon Action'))->toBeFalse()
      ->and(BattleCommandCatalog::isSummonActionId('New Summon Action'))->toBeTrue();
  });
});

it('shares semantic command categories with battle pose playback including authored summons and petitions', function () {
  withCatalogSummonProject(null, null, function (): void {
    BattleCommandCatalog::beginBattle();
    try {
      $actor = new Character('Caller', 1, new Stats(currentMp: 10, totalMp: 10));
      $party = new Party();
      $party->addMember($actor);
      $option = BattleCommandCatalog::buildOptions($actor, $party, BattleCommandType::SUMMON->value)[0];
      expect($option->type)->toBe(BattleCommandType::SUMMON)
        ->and(BattleCommandCatalog::getActionType($option->action))->toBe($option->type)
        ->and(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::getForAction($option->action))
        ->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::SUMMON);
      $ordinary = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new SpecialSkill('Summon', '', '', 0, 0));
      expect(BattleCommandCatalog::getActionType($ordinary))->toBe(BattleCommandType::SKILL)
        ->and(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::getForAction($ordinary))
        ->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::SKILL);
    } finally { BattleCommandCatalog::endBattle(); }
  });
});

it('builds battle magic options from a character spellbook', function () {
  $cure = new MagicSkill('Cure', 'Recover HP.', 'C', 3, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::ALWAYS);
  $fire = new MagicSkill('Fire', 'Deal fire damage.', 'F', 4, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $warp = new MagicSkill('Warp', 'Field-only travel magic.', 'W', 8, 0, new ItemScope(ItemScopeSide::USER), Occasion::MENU_SCREEN);

  $character = new Character('Liora', 500000, new Stats(), spellbook: new Spellbook([$cure, $fire, $warp]));
  $party = new Party();

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Magic');

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Cure', 'Fire'])
    ->and(array_column($options, 'label'))->toBe(['Cure (3 MP)', 'Fire (4 MP)'])
    ->and(array_column($options, 'type'))->toBe([BattleCommandType::MAGIC, BattleCommandType::MAGIC]);
});

it('does not grant database magic when no learned spell is battle usable', function () {
  withCatalogSummonProject(null, null, function (): void {
    writeSkillRecords(getcwd(), ...[new \Ichiloto\Engine\Entities\Skills\MagicSkill('Unlearned spell', '', '', 4, 0)]);
    $field = new MagicSkill('Travel', '', '', 0, 0, occasion: Occasion::MENU_SCREEN);
    $learnable = new LearnableSpell(new MagicSkill('Future spell', '', '', 4, 0));

    foreach ([new Spellbook(), new Spellbook([$field]), new Spellbook([], [$learnable])] as $book) {
      $character = new Character('Caster', 0, new Stats(), spellbook: $book);
      expect(BattleCommandCatalog::buildOptions($character, new Party(), 'Magic'))->toBe([]);
    }
  });
});

it('builds battle skill options from a character ability book', function () {
  $radiantSlash = new SpecialSkill('Radiant Slash', 'Strike one foe.', 'R', 3, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);
  $guardianVow = new SpecialSkill('Guardian Vow', 'Protect one ally.', 'G', 4, 0, new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE), Occasion::BATTLE_SCREEN);

  $character = new Character('Kaelion', 500000, new Stats(), abilityBook: new AbilityBook([$radiantSlash, $guardianVow]));
  $party = new Party();

  $options = BattleCommandCatalog::buildOptions($character, $party, 'Skill');

  expect(array_map(static fn($option) => $option->action->name, $options))
    ->toBe(['Guardian Vow', 'Radiant Slash'])
    ->and(array_column($options, 'label'))->toBe(['Guardian Vow (4 MP)', 'Radiant Slash (3 MP)'])
    ->and(array_column($options, 'type'))->toBe([BattleCommandType::SKILL, BattleCommandType::SKILL]);
});

it('never grants catalogue attacks skills or spells to any member of a party', function () {
  withCatalogSummonProject(null, null, function (): void {
    writeSkillRecords(getcwd(), ...[
  new \Ichiloto\Engine\Entities\Skills\BasicSkill('Enemy strike', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Unowned technique', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\MagicSkill('Unowned spell', '', '', 0, 0),
]);
    $party = new Party();
    for ($index = 0; $index < 8; $index++) {
      $actor = new Character("Actor {$index}", 0, new Stats());
      $party->addMember($actor);
      $attacks = BattleCommandCatalog::buildOptions($actor, $party, 'Attack');
      expect($attacks)->toHaveCount(1)
        ->and($attacks[0]->action)->toBeInstanceOf(AttackAction::class)
        ->and($attacks[0]->source)->toBeNull()
        ->and(BattleCommandCatalog::buildOptions($actor, $party, 'Skill'))->toBe([])
        ->and(BattleCommandCatalog::buildOptions($actor, $party, 'Magic'))->toBe([]);
    }
  });
});

it('partitions owned commands by category without leaking other actors or future grants', function () {
  withCatalogSummonProject(null, null, function (): void {
    $catalog = SkillCatalog::getProjectCatalog();
    $primary = new BasicSkill('Renamed primary strike', '', '', 0, 0);
    $extra = new BasicSkill('Learned alternate strike', '', '', 0, 0);
    $technique = new SpecialSkill('Owned technique', '', '', 0, 0);
    $field = new SpecialSkill('Field technique', '', '', 0, 0, occasion: Occasion::MENU_SCREEN);
    $future = new LearnableAbility(new SpecialSkill('Future technique', '', '', 0, 0));
    $caller = $catalog->findSkill('Test Summon Action');
    $actor = new Character('First actor', 0, new Stats(),
      abilityBook: new AbilityBook([$primary, $extra, $technique, $field, $caller], [$future]),
      attackSkill: $primary);
    $other = new Character('Second actor', 0, new Stats(),
      abilityBook: new AbilityBook([], [$future]));
    $party = new Party();
    $party->addMember($actor);
    $party->addMember($other);
    $attacks = BattleCommandCatalog::buildOptions($actor, $party, 'Attack');
    expect(array_column($attacks, 'source'))->toBe([$primary, $extra])
      ->and(array_column($attacks, 'type'))->toBe([BattleCommandType::ATTACK, BattleCommandType::ATTACK])
      ->and(array_column(BattleCommandCatalog::buildOptions($actor, $party, 'Skill'), 'source'))->toBe([$technique])
      ->and(BattleCommandCatalog::buildOptions($other, $party, 'Skill'))->toBe([])
      ->and(BattleCommandCatalog::buildOptions($other, $party, 'Attack')[0]->source)->toBeNull();
    $other->learnSkill($extra);
    expect(array_column(BattleCommandCatalog::buildOptions($other, $party, 'Attack'), 'source'))->toBe([null, $extra]);
  });
});

it('restores learned basic attacks and current actor attack definitions without saving implicit catalogue grants', function () {
  withCatalogSummonProject(null, null, function (): void {
    writeSkillRecords(getcwd(), ...[
  new \Ichiloto\Engine\Entities\Skills\BasicSkill('Primary strike', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\BasicSkill('Alternate strike', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\BasicSkill('Enemy strike', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Owned technique', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\MagicSkill('Spell', '', '', 0, 0),
]);
    $definition = ActorDefinition::fromArray(['id' => 'actor.synthetic', 'name' => 'Actor',
      'currentExp' => 0, 'stats' => new Stats()->jsonSerialize(), 'attackSkill' => 'Primary strike',
      'abilities' => ['learned' => ['Alternate strike', 'Owned technique']]]);
    $actor = $definition->createCharacter();
    $saved = $actor->toArray();
    expect($saved)->not->toHaveKey('attackSkill')
      ->and($actor->__serialize())->not->toHaveKey('attackSkill');
    $saved['attackSkill'] = 'Enemy strike';
    $restored = $definition->createCharacter($saved);
    expect(array_column(BattleCommandCatalog::buildOptions($restored, new Party(), 'Attack'), 'label'))
      ->toBe(['Primary strike', 'Alternate strike'])
      ->and(array_column(BattleCommandCatalog::buildOptions($restored, new Party(), 'Skill'), 'label'))
      ->toBe(['Owned technique']);
    $saved['abilities'] = [];
    $restored = $definition->createCharacter($saved);
    expect(array_column(BattleCommandCatalog::buildOptions($restored, new Party(), 'Attack'), 'label'))->toBe(['Primary strike'])
      ->and(BattleCommandCatalog::buildOptions($restored, new Party(), 'Skill'))->toBe([]);
    $book = AbilityBook::fromArray(['learned' => ['Alternate strike', 'Spell', 'Missing']]);
    expect(array_column($book->getLearnedAbilities(), 'name'))->toBe(['Alternate strike']);
  });
});

it('rejects invalid configured attacks rather than replacing them with an unrelated catalogue action', function () {
  withCatalogSummonProject(null, null, function (): void {
    writeSkillRecords(getcwd(), ...[
  new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Technique', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\MagicSkill('Spell', '', '', 0, 0),
  new \Ichiloto\Engine\Entities\Skills\BasicSkill('Field only', '', '', 0, 0,
    occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN),
]);
    $data = ['id' => 'actor.synthetic', 'name' => 'Actor', 'currentExp' => 0, 'stats' => new Stats()->jsonSerialize()];
    foreach (['', 42, [], false, 'Missing', 'Technique', 'Spell', 'Field only'] as $reference) {
      expect(fn() => ActorDefinition::fromArray([...$data, 'attackSkill' => $reference])->createCharacter())
        ->toThrow(InvalidArgumentException::class, 'attackSkill');
    }
  });
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
    ->and($options[0]->label)->toBe('Potion x2')
    ->and($options[0]->source)->toBe($potion)
    ->and($options[0]->type)->toBe(BattleCommandType::ITEM);
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
    $option = BattleCommandCatalog::buildOptions($character, $party, 'Summon', [], new GameState())[0];
    expect($option->label)->toBe('Test Summon Action (4 MP)')
      ->and($option->type)->toBe(BattleCommandType::SUMMON);
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
