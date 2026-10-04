<?php

use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\EnemyCatalog;
use Ichiloto\Engine\Entities\Enemies\EnemyRecord;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * Writes a synthetic project with one skill, one enemy sprite and the given
 * enemy record files.
 *
 * @param array<string, string> $records Record file contents, keyed by file name.
 * @return string The project root.
 */
function writeEnemyCatalogProject(array $records): string
{
  $root = sys_get_temp_dir() . '/ichiloto-enemy-catalog-' . bin2hex(random_bytes(4));
  mkdir($root . '/assets/Data/Enemies', 0777, true);
  mkdir($root . '/assets/Graphics/Enemies', 0777, true);
  file_put_contents($root . '/assets/Graphics/Enemies/blob.txt', " (o) \n/   \\\n");
  file_put_contents($root . '/assets/Data/skills.php', "<?php\nuse Ichiloto\\Engine\\Entities\\Skills\\BasicSkill;\n"
    . "return [\n  new BasicSkill('Nip', 'A small bite.', '', 0, 0),\n  new BasicSkill('Brace', 'Holds firm.', '', 0, 0),\n];\n");

  foreach ($records as $file => $contents) {
    file_put_contents($root . '/assets/Data/Enemies/' . $file, $contents);
  }

  return $root;
}

function removeEnemyCatalogProject(string $root): void
{
  $files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );

  foreach ($files as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
  }

  rmdir($root);
}

/**
 * Returns a record file holding the given data.
 *
 * @param array<string, mixed> $data
 */
function enemyRecordFile(array $data): string
{
  return "<?php\n\nuse Ichiloto\\Engine\\Entities\\Enemies\\Enemy;\n\nreturn [\n  'class' => Enemy::class,\n  'data' => "
    . var_export($data, true) . ",\n];\n";
}

/**
 * A complete record exercising every authored value.
 *
 * @return array<string, mixed>
 */
function fullEnemyRecord(string $name = 'Blob'): array
{
  return [
    'name' => $name,
    'level' => 3,
    'imagePath' => 'blob',
    'stats' => [
      'maxHp' => 40, 'maxMp' => 6, 'attack' => 9, 'defence' => 4, 'magicAttack' => 3,
      'magicDefence' => 5, 'speed' => 7, 'grace' => 2, 'evasion' => 1,
    ],
    'rewards' => ['experience' => 12, 'gold' => 30],
    'actionPatterns' => [
      ['skill' => 'Nip', 'rating' => 5],
      ['skill' => 'Brace', 'rating' => 7, 'condition' => ['type' => 'HP', 'range' => [0, 50]]],
      ['skill' => 'Nip', 'rating' => 9, 'condition' => ['type' => 'Turn', 'a' => 2, 'b' => 3]],
    ],
    'position' => [4.0, 1.0],
    'battleAnimation' => 2,
    'stateResistances' => ['poison' => 0.5],
    'elementAffinities' => ['Fire' => 2.0, 'Water' => -1.0],
    'knowledgeSubjectId' => 'creature.blob',
  ];
}

/**
 * Runs a callback with the project root as the working directory, where
 * enemy sprites are read from.
 */
function inEnemyCatalogProject(string $root, callable $callback): mixed
{
  $previous = getcwd();
  chdir($root);

  try {
    return $callback($root . '/assets');
  } finally {
    chdir($previous);
    removeEnemyCatalogProject($root);
  }
}

it('builds an enemy from its record, resolving skills through the shared catalogue', function () {
  inEnemyCatalogProject(writeEnemyCatalogProject(['blob.php' => enemyRecordFile(fullEnemyRecord())]), function (string $assets) {
    $skills = SkillCatalog::load($assets);
    $catalog = EnemyCatalog::load($assets, $skills);
    $blob = $catalog->findEnemy('Blob');

    expect($catalog->getProblems())->toBe([])
      ->and($blob)->toBeInstanceOf(Enemy::class)
      ->and($blob->level)->toBe(3)
      ->and($blob->stats->totalHp)->toBe(40)
      ->and($blob->stats->currentHp)->toBe(40)
      ->and($blob->stats->speed)->toBe(7)
      ->and($blob->rewards->experience)->toBe(12)
      ->and($blob->rewards->gold)->toBe(30)
      ->and($blob->image)->toBe([' (o) ', '/   \\'])
      ->and($blob->actionPatterns)->toHaveCount(3)
      // The catalogue's own skill, so every enemy using it shares one definition.
      ->and($blob->actionPatterns[0]->skill)->toBe($skills->findSkill('Nip'))
      ->and($blob->actionPatterns[1]->condition->type)->toBe(ActionConditionType::HP)
      ->and($blob->actionPatterns[1]->condition->range->max)->toBe(50)
      ->and($blob->actionPatterns[2]->condition->a)->toBe(2)
      ->and($blob->getElementMultiplier('fire'))->toBe(2.0)
      ->and($blob->stateResistances)->toBe(['poison' => 0.5])
      ->and($blob->knowledgeSubjectId)->toBe('creature.blob')
      ->and($catalog->getSourceFile('Blob'))->toBe('Enemies/blob.php');
  });
});

it('writes back exactly the record it read', function () {
  inEnemyCatalogProject(writeEnemyCatalogProject([]), function (string $assets) {
    $skills = SkillCatalog::load($assets);
    $minimal = fullEnemyRecord();
    unset($minimal['actionPatterns'], $minimal['position'], $minimal['battleAnimation'],
      $minimal['stateResistances'], $minimal['elementAffinities'], $minimal['knowledgeSubjectId']);

    foreach ([fullEnemyRecord(), $minimal] as $record) {
      expect(EnemyRecord::writeEnemy(EnemyRecord::readEnemy($record, $skills)))->toBe($record);
    }
  });
});

it('reports a broken record against its file and keeps the other enemies', function (array $record, string $problem) {
  $records = ['blob.php' => enemyRecordFile(fullEnemyRecord()), 'broken.php' => enemyRecordFile($record)];

  inEnemyCatalogProject(writeEnemyCatalogProject($records), function (string $assets) use ($problem) {
    $catalog = EnemyCatalog::load($assets);

    expect(array_keys($catalog->getEnemies()))->toBe(['Blob'])
      ->and($catalog->getProblems())->toHaveCount(1)
      ->and($catalog->getProblems()[0])->toStartWith('Enemies/broken.php: ')
      ->and($catalog->getProblems()[0])->toContain($problem);
  });
})->with([
  'unknown skill' => [
    array_replace(fullEnemyRecord('Broken'), ['actionPatterns' => [['skill' => 'Roar', 'rating' => 5]]]),
    'actionPatterns.0.skill names "Roar"',
  ],
  'missing stat' => [
    (function () { $record = fullEnemyRecord('Broken'); unset($record['stats']['speed']); return $record; })(),
    'stats.speed is required',
  ],
  'unknown key' => [array_replace(fullEnemyRecord('Broken'), ['colour' => 'red']), 'Unknown enemy record key "colour"'],
  'unknown condition' => [
    array_replace(fullEnemyRecord('Broken'), ['actionPatterns' => [['skill' => 'Nip', 'rating' => 5, 'condition' => ['type' => 'Moon']]]]),
    'actionPatterns.0.condition.type "Moon"',
  ],
  'missing sprite' => [array_replace(fullEnemyRecord('Broken'), ['imagePath' => 'ghost']), 'ghost'],
]);

it('reports a file that is not an enemy record', function () {
  $records = ['blob.php' => enemyRecordFile(fullEnemyRecord()), 'list.php' => "<?php\nreturn [1, 2];\n"];

  inEnemyCatalogProject(writeEnemyCatalogProject($records), function (string $assets) {
    expect(EnemyCatalog::load($assets)->getProblems())
      ->toBe(["Enemies/list.php: an enemy record returns ['class' => Enemy::class, 'data' => [...]]."]);
  });
});

it('keeps the first of two records sharing a name', function () {
  $records = ['a.php' => enemyRecordFile(fullEnemyRecord()), 'b.php' => enemyRecordFile(array_replace(fullEnemyRecord(), ['level' => 9]))];

  inEnemyCatalogProject(writeEnemyCatalogProject($records), function (string $assets) {
    $catalog = EnemyCatalog::load($assets);

    expect($catalog->findEnemy('Blob')?->level)->toBe(3)
      ->and($catalog->getProblems()[0])->toContain('Enemies/b.php: "Blob" is already defined by Enemies/a.php');
  });
});

it('returns the project enemies as the list a barrel file returns', function () {
  $records = ['blob.php' => enemyRecordFile(fullEnemyRecord()), 'slime.php' => enemyRecordFile(fullEnemyRecord('Slime'))];

  inEnemyCatalogProject(writeEnemyCatalogProject($records), function (string $assets) {
    $enemies = EnemyCatalog::loadProjectEnemies($assets);

    expect(array_is_list($enemies))->toBeTrue()
      ->and(array_map(static fn(Enemy $enemy): string => $enemy->name, $enemies))->toBe(['Blob', 'Slime']);
  });
});

it('names drops by their stable item identity', function () {
  $store = new ReflectionProperty(ConfigStore::class, 'store');
  $original = $store->getValue();

  inEnemyCatalogProject(writeEnemyCatalogProject([]), function (string $assets) use ($store, $original) {
    file_put_contents($assets . '/Data/items.php', "<?php\nuse Ichiloto\\Engine\\Entities\\Inventory\\Items\\Item;\n"
      . "return [new Item('Pebble', '', '', 1, id: 'item.pebble')];\n");

    try {
      ConfigStore::put(ItemStore::class, new ItemStore());
      $record = array_replace(fullEnemyRecord(), ['rewards' => [
        'experience' => 12,
        'gold' => 30,
        'items' => [['item' => 'Pebble', 'rate' => 0.25]],
      ]]);
      $blob = EnemyRecord::readEnemy($record, SkillCatalog::load($assets));

      expect($blob->rewards->items[0]->item->name)->toBe('Pebble')
        ->and(EnemyRecord::writeEnemy($blob)['rewards']['items'])->toBe([['item' => 'item.pebble', 'rate' => 0.25]]);
    } finally {
      $store->setValue(null, $original);
    }
  });
});
