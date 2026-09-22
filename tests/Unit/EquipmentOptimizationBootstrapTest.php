<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy;
use Ichiloto\Engine\Entities\EquipmentOptimization\EquipmentOptimizationPolicyRegistry;
use Ichiloto\Engine\Entities\EquipmentOptimization\LegacyEqualWeightEquipmentOptimizationPolicy;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/** Exercise production configuration without installing handlers or starting platform services. */
class EquipmentOptimizationBootstrapGame extends Game
{
  public function __construct()
  {
    $this->options = [];
  }

  public function initializeProjectConfiguration(): void
  {
    new ReflectionMethod(Game::class, 'initializeConfigStore')->invoke($this);
  }

  public function __destruct()
  {
  }
}

function writeOptimizationBootstrapProject(string $root, string $name): string
{
  $project = $root . '/' . $name;
  mkdir($project . '/assets/Data', 0777, true);
  file_put_contents($project . '/ichiloto.json', '{"id":"optimization-bootstrap-fixture"}');
  foreach (['config.php', 'input.php', 'assets/Data/system.php', 'assets/Data/enemies.php'] as $path) {
    file_put_contents($project . '/' . $path, '<?php return [];');
  }
  file_put_contents($project . '/assets/Data/items.php', <<<'PHP'
  <?php
  use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
  use Ichiloto\Engine\Entities\ParameterChanges;
  return [
    new Weapon('Physical', '', '/', 10, parameterChanges: new ParameterChanges(attack: 10), id: 'equipment.physical'),
    new Weapon('Arcane', '', '/', 10, parameterChanges: new ParameterChanges(magicAttack: 4), id: 'equipment.arcane'),
  ];
  PHP);

  return $project;
}

function writeOptimizationBootstrapPolicy(string $project, mixed $declaration): void
{
  file_put_contents($project . '/assets/Data/equipment-optimization.php', '<?php return ' . var_export($declaration, true) . ';');
}

function optimizeBootstrapCharacter(string $roleName): Character
{
  $character = new Character('Policy Test', 0, new Stats());
  new ReflectionProperty(Character::class, 'role')->setValue($character, new CharacterRole($character, $roleName));
  $party = new Party();
  $party->addMember($character);
  $items = ConfigStore::get(ItemStore::class);
  assert($items instanceof ItemStore);
  $party->inventory->addItems(...$items->instantiate('equipment.physical'), ...$items->instantiate('equipment.arcane'));
  $character->optimizeEquipment($party->inventory, $party);

  return $character;
}

beforeEach(function () {
  $this->originalDirectory = getcwd();
  $this->originalConfig = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->originalElements = new ReflectionProperty(ElementRegistry::class, 'elements')->getValue();
  $this->originalPolicy = new ReflectionProperty(EquipmentOptimizationPolicyRegistry::class, 'policy')->getValue();
  $this->root = sys_get_temp_dir() . '/' . uniqid('ichiloto-optimization-bootstrap-', true);
  $this->project = writeOptimizationBootstrapProject($this->root, 'first');
  $this->game = new EquipmentOptimizationBootstrapGame();
  chdir($this->project);
});

afterEach(function () {
  chdir($this->originalDirectory);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->originalConfig);
  new ReflectionProperty(ElementRegistry::class, 'elements')->setValue(null, $this->originalElements);
  EquipmentOptimizationPolicyRegistry::configure($this->originalPolicy);
  $files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );
  foreach ($files as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
  }
  rmdir($this->root);
});

it('loads the Editor policy file through Game bootstrap for normal character optimization', function () {
  writeOptimizationBootstrapPolicy($this->project, [
    'statWeights' => ['attack' => 1, 'magicAttack' => 1],
    'roleStatWeights' => [
      ' Striker ' => ['attack' => 3, 'magicAttack' => 0],
      ' Mystic ' => ['attack' => 0, 'magicAttack' => 3],
    ],
  ]);
  $this->game->initializeProjectConfiguration();

  expect(EquipmentOptimizationPolicyRegistry::current())->toBeInstanceOf(DeclaredEquipmentOptimizationPolicy::class)
    ->and(optimizeBootstrapCharacter('striker')->equipment[0]->equipment?->id)->toBe('equipment.physical')
    ->and(optimizeBootstrapCharacter('MYSTIC')->equipment[0]->equipment?->id)->toBe('equipment.arcane');
});

it('preserves all nine declaration fields and the existing weight precedence', function () {
  writeOptimizationBootstrapPolicy($this->project, [
    'statWeights' => ['attack' => 1, 'accuracy' => 2, 'critical' => -1],
    'roleStatWeights' => ['mystic' => ['attack' => 3]],
    'slotStatWeights' => ['weapon' => ['attack' => 4]],
    'roleSlotStatWeights' => [' MYSTIC ' => [' WEAPON ' => [' ATTACK ' => 5]]],
    'elementOutcomeWeights' => [' Offence:Fire ' => 7, 'defence:*:absorb' => 8],
    'specialPropertyWeights' => [' Counter ' => 9],
    'excludedDefinitionIds' => [' equipment.excluded '],
    'excludedAvailabilities' => [' STORY-ONLY '],
    'excludedAcquisitionPolicies' => [' Unique '],
  ]);
  $this->game->initializeProjectConfiguration();
  $character = optimizeBootstrapCharacter('mystic');
  $policy = EquipmentOptimizationPolicyRegistry::current();
  $weapon = new Weapon('Scored', '', '/', 10,
    parameterChanges: new ParameterChanges(attack: 2),
    elementAffinities: ['Ice' => -1.0], element: 'Fire', id: 'equipment.scored',
    accuracyModifier: 3, criticalModifier: 2, specialProperty: ['type' => 'counter'],
  );
  $score = $policy->score($character, $character->equipment[0], $weapon);

  expect($score?->value)->toBe(38)
    ->and($score?->components)->toBe([
      'attack' => 10, 'accuracy' => 6, 'critical' => -2,
      'element:offence:fire' => 7, 'element:defence:*:absorb' => 8, 'special:counter' => 9,
    ]);
  foreach ([
    new Weapon('Excluded ID', '', '/', 10, id: 'equipment.excluded'),
    new Weapon('Excluded availability', '', '/', 10, availability: 'story-only'),
    new Weapon('Excluded acquisition', '', '/', 10, acquisitionPolicy: 'unique'),
  ] as $excluded) {
    expect($policy->score($character, $character->equipment[0], $excluded))->toBeNull();
  }
});

it('resets stale policy when the next project omits the declaration', function () {
  writeOptimizationBootstrapPolicy($this->project, ['statWeights' => ['magicAttack' => 3]]);
  $this->game->initializeProjectConfiguration();
  expect(optimizeBootstrapCharacter('mystic')->equipment[0]->equipment?->id)->toBe('equipment.arcane');

  chdir(writeOptimizationBootstrapProject($this->root, 'second'));
  $this->game->initializeProjectConfiguration();

  expect(EquipmentOptimizationPolicyRegistry::current())->toBeInstanceOf(LegacyEqualWeightEquipmentOptimizationPolicy::class)
    ->and(optimizeBootstrapCharacter('mystic')->equipment[0]->equipment?->id)->toBe('equipment.physical');
});

it('reloads an explicitly empty declaration as a zero-weight policy rather than legacy', function () {
  writeOptimizationBootstrapPolicy($this->project, ['statWeights' => ['attack' => 3]]);
  $this->game->initializeProjectConfiguration();
  expect(optimizeBootstrapCharacter('mystic')->equipment[0]->equipment?->id)->toBe('equipment.physical');

  writeOptimizationBootstrapPolicy($this->project, []);
  $this->game->initializeProjectConfiguration();
  $character = optimizeBootstrapCharacter('mystic');
  $weapon = $character->equipment[0]->equipment;
  expect(EquipmentOptimizationPolicyRegistry::current())->toBeInstanceOf(DeclaredEquipmentOptimizationPolicy::class)
    ->and($weapon?->id)->toBe('equipment.arcane');
  expect(EquipmentOptimizationPolicyRegistry::current()->score($character, $character->equipment[0], $weapon)?->value)->toBe(0);
});

it('fails bootstrap visibly on malformed declarations instead of retaining the prior policy', function (string $source, string $message) {
  writeOptimizationBootstrapPolicy($this->project, ['statWeights' => ['attack' => 3]]);
  $this->game->initializeProjectConfiguration();
  $filename = $this->project . '/assets/Data/equipment-optimization.php';
  file_put_contents($filename, $source);

  try {
    $this->game->initializeProjectConfiguration();
    $this->fail('Malformed declared policy must reject bootstrap.');
  } catch (RuntimeException $exception) {
    expect($exception->getMessage())->toContain($filename)->toContain($message)
      ->and($exception->getPrevious())->toBeInstanceOf(Throwable::class);
  }
  expect(EquipmentOptimizationPolicyRegistry::current())->toBeInstanceOf(LegacyEqualWeightEquipmentOptimizationPolicy::class);
})->with([
  'scalar declaration' => ['<?php return 12;', 'must return an array'],
  'null declaration' => ['<?php return null;', 'must return an array'],
  'object declaration' => ['<?php return new stdClass();', 'must return an array'],
  'positional arguments' => ['<?php return [["attack" => 1]];', 'Unknown equipment optimization field'],
  'unknown field' => ['<?php return ["roleWeights" => []];', 'Unknown equipment optimization field: roleWeights'],
  'null field' => ['<?php return ["statWeights" => null];', 'statWeights must be an array'],
  'scalar field' => ['<?php return ["roleStatWeights" => 1];', 'roleStatWeights must be an array'],
  'unknown stat' => ['<?php return ["statWeights" => ["damage" => 1]];', 'Unknown base equipment optimization key'],
  'string weight' => ['<?php return ["statWeights" => ["attack" => "3"]];', 'require integer values'],
  'float weight' => ['<?php return ["statWeights" => ["attack" => 3.0]];', 'require integer values'],
  'boolean weight' => ['<?php return ["statWeights" => ["attack" => true]];', 'require integer values'],
  'positional stat' => ['<?php return ["statWeights" => [3]];', 'names must be non-empty strings'],
  'empty role' => ['<?php return ["roleStatWeights" => [" " => []]];', 'names must be non-empty strings'],
  'positional role' => ['<?php return ["roleStatWeights" => [[]]];', 'names must be non-empty strings'],
  'scalar role weights' => ['<?php return ["roleStatWeights" => ["mystic" => 1]];', 'weights must be arrays'],
  'scalar slot weights' => ['<?php return ["slotStatWeights" => ["weapon" => 1]];', 'weights must be arrays'],
  'scalar role-slot map' => ['<?php return ["roleSlotStatWeights" => ["mystic" => 1]];', 'weights must be arrays'],
  'positional role-slot role' => ['<?php return ["roleSlotStatWeights" => [["weapon" => []]]];', 'names must be non-empty strings'],
  'positional role-slot slot' => ['<?php return ["roleSlotStatWeights" => ["mystic" => [[]]]];', 'names must be non-empty strings'],
  'scalar role-slot weights' => ['<?php return ["roleSlotStatWeights" => ["mystic" => ["weapon" => 1]]];', 'weights must be arrays'],
  'positional element outcome' => ['<?php return ["elementOutcomeWeights" => [3]];', 'names must be non-empty strings'],
  'empty special name' => ['<?php return ["specialPropertyWeights" => [" " => 3]];', 'names must be non-empty strings'],
  'string special weight' => ['<?php return ["specialPropertyWeights" => ["counter" => "3"]];', 'integer values'],
  'non-list exclusion' => ['<?php return ["excludedDefinitionIds" => ["id" => "equipment.example"]];', 'exclusions must be a list'],
  'numeric excluded ID' => ['<?php return ["excludedDefinitionIds" => [1]];', 'names must be non-empty strings'],
  'empty excluded availability' => ['<?php return ["excludedAvailabilities" => [" "]];', 'names must be non-empty strings'],
  'array excluded acquisition' => ['<?php return ["excludedAcquisitionPolicies" => [[]]];', 'names must be non-empty strings'],
  'declaration throws' => ['<?php throw new LogicException("broken policy declaration");', 'broken policy declaration'],
  'syntax error' => ['<?php return [;', 'syntax error'],
]);

it('rejects a directory at the declared policy path rather than treating it as absent', function () {
  mkdir($this->project . '/assets/Data/equipment-optimization.php');

  expect(fn() => $this->game->initializeProjectConfiguration())
    ->toThrow(RuntimeException::class, 'must be a readable PHP file');
});
