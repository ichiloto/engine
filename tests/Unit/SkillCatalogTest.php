<?php

use Ichiloto\Engine\Entities\Abilities\AbilityLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Magic\MagicLibrary;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Util\Stores\ClassStore;

/**
 * Writes a project whose skills are spread across every catalogue file.
 *
 * @param array<string, string> $files Data file contents, keyed by file name.
 * @return string The project root.
 */
function writeSkillCatalogProject(array $files): string
{
  $root = sys_get_temp_dir() . '/ichiloto-skill-catalog-' . bin2hex(random_bytes(4));
  mkdir($root . '/assets/Data', 0777, true);

  foreach ($files as $file => $contents) {
    file_put_contents($root . '/assets/Data/' . $file, $contents);
  }

  return $root;
}

function removeSkillCatalogProject(string $root): void
{
  foreach (glob($root . '/assets/Data/*') ?: [] as $file) {
    unlink($file);
  }

  rmdir($root . '/assets/Data');
  rmdir($root . '/assets');
  rmdir($root);
}

/**
 * The same catalogue, authored the way a project spreads it: a spell and an
 * ability in skills.php alongside the attack, more abilities and spells in
 * their own files.
 *
 * @return array<string, string>
 */
function spreadSkillCatalogFiles(): array
{
  $header = "<?php\nuse Ichiloto\\Engine\\Entities\\Skills\\BasicSkill;\n"
    . "use Ichiloto\\Engine\\Entities\\Skills\\MagicSkill;\n"
    . "use Ichiloto\\Engine\\Entities\\Skills\\SpecialSkill;\n";

  return [
    'skills.php' => $header . "return [\n"
      . "  new BasicSkill('Attack', 'Strikes.', '', 0, 0),\n"
      . "  new SpecialSkill('Lunge', 'Strikes far.', '', 4, 0),\n"
      . "  new MagicSkill('Purify', 'Lifts a poison.', '', 3, 0),\n"
      . "];\n",
    'abilities.php' => $header . "return [\n"
      . "  'Ward' => new SpecialSkill('Ward', 'Guards.', '', 2, 0),\n"
      . "];\n",
    'magic.php' => $header . "return [\n"
      . "  'Ember' => new MagicSkill('Ember', 'Burns.', '', 5, 0),\n"
      . "];\n",
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
      ->and($catalog->getSourceFile('Purify'))->toBe('skills.php')
      ->and($catalog->getSourceFile('Ember'))->toBe('magic.php')
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

it('reports a name defined twice and keeps the first definition', function () {
  $catalog = SkillCatalog::fromFiles([
    'skills.php' => [new MagicSkill('Ember', 'First.', '', 5, 0)],
    'magic.php' => ['Ember' => new MagicSkill('Ember', 'Second.', '', 5, 0)],
  ]);

  expect($catalog->findSkill('Ember')?->description)->toBe('First.')
    ->and($catalog->getProblems())->toBe([
      '"Ember" is defined in both skills.php and magic.php; skill names must be unique, so the one in skills.php is used.',
    ]);
});

it('reports entries it cannot identify instead of hiding them', function () {
  $catalog = SkillCatalog::fromFiles([
    'skills.php' => ['Attack', new SpecialSkill('Lunge', 'Strikes far.', '', 4, 0)],
    'abilities.php' => 'not a list',
    'magic.php' => ['Fire' => new MagicSkill('Burn I', 'Burns.', '', 5, 0)],
  ]);

  expect(array_keys($catalog->getSkills()))->toBe(['Lunge', 'Burn I'])
    ->and($catalog->getProblems())->toBe([
      'skills.php entry 0 is not a skill.',
      'abilities.php must return an array of skills.',
      'magic.php registers "Burn I" under the key "Fire"; a skill is found by its name, so the key must match it.',
    ]);
});

it('reports a data file that fails to load', function () {
  $root = writeSkillCatalogProject(['magic.php' => "<?php\nthrow new RuntimeException('broken');\n"]);

  try {
    expect(SkillCatalog::load($root . '/assets')->getProblems())->toBe(['magic.php could not be read: broken']);
  } finally {
    removeSkillCatalogProject($root);
  }
});
