<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Entities\EquipmentOptimization\EquipmentOptimizationPolicyRegistry;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Progression\ProgressionSettings;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Only production configuration runs: no handlers, rendering, input or audio acquisition. */
class ProgressionConfigurationGame extends Game
{
  public function __construct() { $this->options = []; }
  public function initializeProjectConfiguration(): void
  {
    new ReflectionMethod(Game::class, 'initializeConfigStore')->invoke($this);
  }
  public function __destruct() {}
}

function createProgressionSystemData(array $extra = []): array
{
  return array_replace([
    'title' => 'Progression Fixture',
    'currency' => ['name' => 'Coins', 'symbol' => 'C'],
    'startingParty' => [],
    'startingInventory' => [],
    'startingPositions' => ['player' => ['mapId' => 'fixture/start', 'position' => ['x' => 0, 'y' => 0]]],
    'battle' => [],
    'elements' => [],
  ], $extra);
}

beforeEach(function () {
  $this->previousDirectory = getcwd();
  $this->previousConfig = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->previousElements = new ReflectionProperty(ElementRegistry::class, 'elements')->getValue();
  $this->previousEquipmentPolicy = new ReflectionProperty(EquipmentOptimizationPolicyRegistry::class, 'policy')->getValue();
  $this->previousScriptCatalog = new ReflectionProperty(ScriptCommandRegistry::class, 'catalog')->getValue();
});

afterEach(function () {
  chdir($this->previousDirectory);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->previousConfig);
  new ReflectionProperty(ElementRegistry::class, 'elements')->setValue(null, $this->previousElements);
  EquipmentOptimizationPolicyRegistry::configure($this->previousEquipmentPolicy);
  ScriptCommandRegistry::configure($this->previousScriptCatalog);
});

it('loads the authorable progression contract without changing other system data', function (array $extra, bool $restore) {
  $data = createProgressionSystemData($extra);
  $system = SystemData::fromArray($data);

  expect($system->progression->restoreVitalsOnLevelUp)->toBe($restore)
    ->and($system->progression->get('restoreVitalsOnLevelUp'))->toBe($restore)
    ->and($system->title)->toBe($data['title'])
    ->and($system->startingParty)->toBe($data['startingParty'])
    ->and($system->startingInventory)->toBe($data['startingInventory'])
    ->and(json_decode(json_encode($system->startingPositions), true))->toBe($data['startingPositions'])
    ->and($system->elements)->toBe($data['elements']);
})->with([
  'omitted section' => [[], true],
  'omitted key' => [['progression' => []], true],
  'restore' => [['progression' => ['restoreVitalsOnLevelUp' => true]], true],
  'preserve' => [['progression' => ['restoreVitalsOnLevelUp' => false]], false],
]);

it('rejects malformed progression sections and fields rather than coercing authored data', function (mixed $section) {
  expect(fn() => SystemData::fromArray(createProgressionSystemData(['progression' => $section])))
    ->toThrow(InvalidArgumentException::class, 'system.progression');
})->with([
  'null section' => [null],
  'boolean section' => [false],
  'numeric section' => [0],
  'string section' => ['false'],
  'object section' => [(object) ['restoreVitalsOnLevelUp' => false]],
  'list section' => [[false]],
  'unknown key' => [['restoreOnLevelUp' => false]],
  'unknown mixed key' => [['restoreVitalsOnLevelUp' => false, 'other' => true]],
  'null value' => [['restoreVitalsOnLevelUp' => null]],
  'integer value' => [['restoreVitalsOnLevelUp' => 1]],
  'zero value' => [['restoreVitalsOnLevelUp' => 0]],
  'float value' => [['restoreVitalsOnLevelUp' => 1.0]],
  'string true' => [['restoreVitalsOnLevelUp' => 'true']],
  'string false' => [['restoreVitalsOnLevelUp' => 'false']],
  'array value' => [['restoreVitalsOnLevelUp' => []]],
]);

it('keeps project progression immutable at runtime', function () {
  $settings = ProgressionSettings::fromArray(['restoreVitalsOnLevelUp' => false]);
  expect($settings->has('restoreVitalsOnLevelUp'))->toBeTrue()
    ->and($settings->has('other'))->toBeFalse()
    ->and($settings->get('other', 'missing'))->toBe('missing')
    ->and(fn() => $settings->set('restoreVitalsOnLevelUp', true))->toThrow(LogicException::class)
    ->and(fn() => $settings->persist())->toThrow(LogicException::class)
    ->and($settings->restoreVitalsOnLevelUp)->toBeFalse();
});

it('uses the authored setting through production bootstrap and resets it for a later project declaration', function () {
  $project = createTestDirectory('ichiloto-progression-');
  mkdir($project . '/assets/Data', 0700, true);
  file_put_contents($project . '/ichiloto.json', '{"id":"progression-fixture","format":1}');
  foreach (['config.php', 'input.php', 'assets/Data/items.php', 'assets/Data/enemies.php'] as $path) {
    file_put_contents($project . '/' . $path, '<?php return [];');
  }
  $path = $project . '/assets/Data/system.php';
  chdir($project);
  $game = new ProgressionConfigurationGame();

  foreach ([false, true, null] as $restore) {
    $data = createProgressionSystemData($restore === null ? [] : ['progression' => ['restoreVitalsOnLevelUp' => $restore]]);
    $source = '<?php return ' . var_export($data, true) . ';';
    file_put_contents($path, $source);
    $game->initializeProjectConfiguration();
    $character = new Character('Bootstrap Hero', 0, new Stats(currentHp: 100, currentMp: 30));
    $character->stats->currentHp = 7;
    $character->stats->currentMp = 3;
    $result = ExperienceAwarder::award($character, $character->getLevelExperienceThresholds()[2]);

    expect(ConfigStore::get(ProgressionSettings::class)->get('restoreVitalsOnLevelUp'))->toBe($restore ?? true)
      ->and($result->levelledUp())->toBeTrue()
      ->and($character->stats->currentHp)->toBe($restore === false ? 7 : $character->stats->totalHp)
      ->and($character->stats->currentMp)->toBe($restore === false ? 3 : $character->stats->totalMp)
      ->and(file_get_contents($path))->toBe($source)
      ->and(file_exists($project . '/.data/player-settings.json'))->toBeFalse();
  }

  file_put_contents($path, '<?php return ' . var_export(createProgressionSystemData([
    'progression' => ['restoreVitalsOnLevelUp' => 'false'],
  ]), true) . ';');
  expect(fn() => $game->initializeProjectConfiguration())->toThrow(InvalidArgumentException::class, 'system.progression')
    ->and(ConfigStore::has(ProgressionSettings::class))->toBeFalse();
});
