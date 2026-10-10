<?php

use Ichiloto\Engine\Entities\Abilities\AbilityLibrary;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Magic\MagicLibrary;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Util\Stores\ClassStore;

/**
 * Writes a project with the given data files.
 *
 * @param array<string, string> $files File contents, keyed by path under `assets/Data`.
 * @return string The project root.
 */
function writeSkillCatalogProject(array $files): string
{
  $root = sys_get_temp_dir() . '/ichiloto-skill-catalog-' . bin2hex(random_bytes(4));
  mkdir($root . '/assets/Data/' . SkillCatalog::DIRECTORY, 0777, true);

  foreach ($files as $file => $contents) {
    file_put_contents($root . '/assets/Data/' . $file, $contents);
  }

  return $root;
}

function removeSkillCatalogProject(string $root): void
{
  $entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );

  foreach ($entries as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
  }

  rmdir($root);
}

/**
 * One skill record file, as a project authors it.
 */
function skillRecordFile(string $kind, string $name, string $description, int $cost): string
{
  $data = var_export([
    'kind' => $kind,
    'name' => $name,
    'description' => $description,
    'icon' => '',
    'cost' => $cost,
    'cooldown' => 0,
    'occasion' => 'Always',
    'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '$1 casts $2!', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 10],
    'effects' => [],
  ], true);

  return "<?php\nuse Ichiloto\\Engine\\Entities\\Skills\\Skill;\nreturn ['class' => Skill::class, 'data' => {$data}];\n";
}

/**
 * The same catalogue a project authors one record per skill, numbered in
 * the order its menus list them: the attack, abilities and spells.
 *
 * @return array<string, string>
 */
function spreadSkillCatalogFiles(): array
{
  return [
    'Skills/0001-attack.php' => skillRecordFile('basic', 'Attack', 'Strikes.', 0),
    'Skills/0002-lunge.php' => skillRecordFile('special', 'Lunge', 'Strikes far.', 4),
    'Skills/0003-purify.php' => skillRecordFile('magic', 'Purify', 'Lifts a poison.', 3),
    'Skills/0004-ward.php' => skillRecordFile('special', 'Ward', 'Guards.', 2),
    'Skills/0005-ember.php' => skillRecordFile('magic', 'Ember', 'Burns.', 5),
  ];
}
/**
 * Runs a callback as the running project at the given root.
 */
function runInSkillCatalogProject(string $root, callable $callback): mixed
{
  $previous = getcwd();
  $definitions = new ReflectionProperty(ClassStore::class, 'definitions');
  chdir($root);
  $definitions->setValue(null, null);

  try {
    return $callback();
  } finally {
    $definitions->setValue(null, null);
    chdir($previous);
  }
}

it('identifies every skill by name, whichever file authors it', function () {
  $root = writeSkillCatalogProject(spreadSkillCatalogFiles());

  try {
    $catalog = SkillCatalog::load($root . '/assets');

    expect(array_keys($catalog->getSkills()))->toBe(['Attack', 'Lunge', 'Purify', 'Ward', 'Ember'])
      ->and(array_keys($catalog->getSpells()))->toBe(['Purify', 'Ember'])
      ->and(array_keys($catalog->getAbilities()))->toBe(['Lunge', 'Ward'])
      ->and($catalog->findSkill('Attack'))->toBeInstanceOf(BasicSkill::class)
      ->and($catalog->getSourceFile('Purify'))->toBe('Skills/0003-purify.php')
      ->and($catalog->getSourceFile('Ember'))->toBe('Skills/0005-ember.php')
      ->and($catalog->getProblems())->toBe([]);
  } finally {
    removeSkillCatalogProject($root);
  }
});

it('gives the spellbook, the ability book and class grants the same catalogue', function () {
  $files = spreadSkillCatalogFiles();
  $files['classes.php'] = "<?php\nreturn [[\n  'name' => 'Adept',\n  'skillsToLearn' => [\n"
    . "    ['level' => 2, 'skill' => 'Purify'],\n"
    . "    ['level' => 3, 'skill' => 'Ember'],\n"
    . "    ['level' => 4, 'skill' => 'Ward'],\n"
    . "  ],\n]];\n";
  $root = writeSkillCatalogProject($files);

  try {
    $result = runInSkillCatalogProject($root, static function (): array {
      $character = new Character('Adept', 0, new Stats(100, 10, 5, 5, 5, 5, 5, 5, 5));
      $role = ClassStore::createRole('Adept', $character);

      return [
        'spells' => array_keys(MagicLibrary::all()),
        'abilities' => array_keys(AbilityLibrary::all()),
        'grants' => array_map(static fn($grant): string => $grant->skill->name, $role?->skillsToLearn ?? []),
      ];
    });

    expect($result['spells'])->toBe(['Purify', 'Ember'])
      ->and($result['abilities'])->toBe(['Lunge', 'Ward'])
      ->and($result['grants'])->toBe(['Purify', 'Ember', 'Ward']);
  } finally {
    removeSkillCatalogProject($root);
  }
});

it('resolves battle commands from the same catalogue without unlocking unlearned magic', function () {
  $root = writeSkillCatalogProject(spreadSkillCatalogFiles());
  try {
    runInSkillCatalogProject($root, static function (): void {
      $character = Character::fromArray(['name' => 'Adept', 'currentExp' => 0,
        'stats' => new Stats()->jsonSerialize(), 'attackSkill' => 'Attack']);
      $party = new Party();
      $getNames = static fn($command) => array_map(static fn($option) => $option->action->name,
        BattleCommandCatalog::buildOptions($character, $party, $command));
      expect($getNames('Attack'))->toBe(['Attack'])
        ->and($getNames('Skill'))->toBe([])
        ->and($getNames('Magic'))->toBe([]);
      $catalog = SkillCatalog::getProjectCatalog();
      foreach (['Lunge', 'Ward', 'Ember'] as $name) {
        $character->learnSkill($catalog->findSkill($name));
      }
      $attacks = BattleCommandCatalog::buildOptions($character, $party, 'Attack');
      expect($getNames('Attack'))->toBe(['Attack'])
        ->and($attacks[0]->source)->toBe($catalog->findSkill('Attack'))
        ->and($getNames('Skill'))->toBe(['Lunge', 'Ward'])
        ->and($getNames('Magic'))->toBe(['Ember']);
    });
  } finally {
    removeSkillCatalogProject($root);
  }
});

it('reports a name defined twice and keeps the first definition', function () {
  $catalog = SkillCatalog::fromSkills([
    'Skills/0001-ember.php' => new MagicSkill('Ember', 'First.', '', 5, 0),
    'Skills/0002-ember-again.php' => new MagicSkill('Ember', 'Second.', '', 5, 0),
  ]);

  expect($catalog->findSkill('Ember')?->description)->toBe('First.')
    ->and($catalog->getProblems())->toBe([
      'Skills/0002-ember-again.php: "Ember" is already defined by Skills/0001-ember.php; skill names must be unique, so this file is skipped.',
    ]);
});

it('reports record files it cannot read instead of hiding them, keeping the others', function () {
  $root = writeSkillCatalogProject([
    'Skills/0001-lunge.php' => skillRecordFile('special', 'Lunge', 'Strikes far.', 4),
    'Skills/0002-loose.php' => "<?php\nreturn ['name' => 'Loose'];\n",
    'Skills/0003-odd.php' => skillRecordFile('summon', 'Odd', 'Unknown kind.', 0),
    'Skills/0004-broken.php' => "<?php\nthrow new RuntimeException('broken');\n",
  ]);

  try {
    $catalog = SkillCatalog::load($root . '/assets');

    expect(array_keys($catalog->getSkills()))->toBe(['Lunge'])
      ->and($catalog->getProblems())->toBe([
        "Skills/0002-loose.php: a skill record returns ['class' => Skill::class, 'data' => [...]].",
        'Skills/0003-odd.php: kind "summon" is not one of basic, special, magic.',
        'Skills/0004-broken.php: broken',
      ]);
  } finally {
    removeSkillCatalogProject($root);
  }
});

it('is what a project\'s skills.php barrel returns', function () {
  $root = writeSkillCatalogProject(spreadSkillCatalogFiles());

  try {
    $skills = SkillCatalog::loadProjectSkills($root . '/assets');

    expect(array_map(static fn($skill): string => $skill->name, $skills))->toBe(['Attack', 'Lunge', 'Purify', 'Ward', 'Ember']);
  } finally {
    removeSkillCatalogProject($root);
  }
});

it('keeps each project root its own catalogue', function () {
  $first = writeSkillCatalogProject(['Skills/0001-ember.php' => skillRecordFile('magic', 'Ember', 'Burns.', 5)]);
  $second = writeSkillCatalogProject(['Skills/0001-frost.php' => skillRecordFile('magic', 'Frost', 'Chills.', 5)]);

  try {
    $firstSpells = runInSkillCatalogProject($first, static fn(): array => array_keys(MagicLibrary::all()));
    $secondSpells = runInSkillCatalogProject($second, static fn(): array => array_keys(MagicLibrary::all()));
    $firstAgain = runInSkillCatalogProject($first, static fn(): ?string => MagicLibrary::find('Frost')?->name);

    expect($firstSpells)->toBe(['Ember'])
      ->and($secondSpells)->toBe(['Frost'])
      ->and($firstAgain)->toBeNull();
  } finally {
    removeSkillCatalogProject($first);
    removeSkillCatalogProject($second);
  }
});
