<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Core\PackedGrid;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\EncounterArenaCoverage;
use Ichiloto\Engine\Field\EncounterTable;
use Ichiloto\Engine\Field\MapAreaCatalog;
use Ichiloto\Engine\Field\MapEncounterTerrain;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

// The battle background follows where a random battle starts: the map's arena
// for the cell's area, refined by the encounter terrain of the cell. Arenas,
// areas and terrains here are synthetic.

/** @param array<string, string> $variants */
function terrainArena(string $name, array $variants = []): BattleArenaDefinition
{
  return new BattleArenaDefinition($name, new CanvasImage(strtolower($name), "Graphics/{$name}.png", new CanvasRectangle(0, 0, 10, 10)),
    variants: $variants);
}

function terrainArenaCatalog(?string $default = 'arena.region'): BattlePresentationCatalog
{
  return new BattlePresentationCatalog([
    'arena.region' => terrainArena('Region', ['woodland' => 'arena.woods', 'grassland' => 'arena.region']),
    'arena.woods' => terrainArena('Woods'),
    'arena.marsh' => terrainArena('Marsh'),
    'arena.vale' => terrainArena('Vale', ['woodland' => 'arena.woods']),
    'arena.vale-marsh' => terrainArena('Vale marsh'),
  ], [], [], defaultArena: $default);
}

/** A 6x2 map: its first three columns grassland, then woodland, wetland and none; a vale over its last three. */
function terrainArenaTable(array $block): EncounterTable
{
  $terrain = new MapEncounterTerrain(PackedGrid::fromRows([[1, 1, 1, 2, 3, 0], [1, 1, 1, 2, 3, 0]]), ['grassland', 'woodland', 'wetland']);
  $areas = MapAreaCatalog::readMap(['areas' => [['id' => 'vale', 'area' => ['x' => 3, 'y' => 0, 'width' => 3, 'height' => 2]]]],
    array_fill(0, 2, array_fill(0, 6, ' ')));

  return EncounterTable::readBlock(['troops' => ['Wolves' => 1, 'Lake Wyrm' => ['weight' => 1, 'battleArena' => 'arena.marsh']],
    'tiles' => 'any', ...$block], $areas, $terrain);
}

function terrainBattle(array $settings): BattleConfig
{
  return new BattleConfig(new Party(), new Troop('Synthetic'), settings: $settings);
}

it('reads an arena\'s terrain variants as data and refuses ones the catalog cannot draw', function () {
  $bindings = BattleArenaBindings::getFromArray(['arenas' => [
    'arena.region' => ['name' => 'Region', 'background' => ['image' => 'Graphics/Region.png'], 'variants' => ['woodland' => 'arena.woods']],
    'arena.woods' => ['name' => 'Woods', 'background' => ['image' => 'Graphics/Woods.png']],
  ]]);

  expect($bindings->getDefinitions(10, 10)['arena.region']->variants)->toBe(['woodland' => 'arena.woods'])
    ->and($bindings->getDefinitions(10, 10)['arena.woods']->variants)->toBe([])
    ->and(fn() => BattleArenaBindings::getFromArray(['arenas' => ['arena.region' => ['name' => 'Region',
      'background' => ['image' => 'Graphics/Region.png'], 'variants' => ['Deep Woods' => 'arena.woods']]]]))
    ->toThrow(InvalidArgumentException::class, 'encounter terrain')
    ->and(fn() => new BattlePresentationCatalog(['arena.region' => terrainArena('Region', ['woodland' => 'arena.gone'])], [], []))
    ->toThrow(InvalidArgumentException::class, 'arena.gone');
});

it('draws a battle on its arena\'s variant for the terrain it started on', function () {
  $catalog = terrainArenaCatalog();

  expect($catalog->getArenaFor(terrainBattle(['battleArena' => 'arena.region', 'battleTerrain' => 'woodland']))->name)->toBe('Woods')
    ->and($catalog->getArenaFor(terrainBattle(['battleTerrain' => 'woodland']))->name)->toBe('Woods')
    ->and($catalog->getArenaFor(terrainBattle(['battleArena' => 'arena.region', 'battleTerrain' => 'grassland']))->name)->toBe('Region')
    ->and($catalog->resolveArenaVariant('arena.region', 'grassland')->isMissingVariant)->toBeFalse();
});

it('falls back to the arena\'s own background for a missing variant and reports it', function () {
  $catalog = terrainArenaCatalog();
  $choice = $catalog->resolveArenaVariant('arena.region', 'wetland');

  expect($choice->key)->toBe('arena.region')
    ->and($choice->isMissingVariant)->toBeTrue()
    ->and($catalog->getArenaFor(terrainBattle(['battleArena' => 'arena.region', 'battleTerrain' => 'wetland']))->name)->toBe('Region')
    ->and(fn() => $catalog->getArenaFor(terrainBattle(['battleTerrain' => 'Deep Woods'])))->toThrow(InvalidArgumentException::class);
});

it('keeps an event battle on the arena it names, whatever variants the arena has', function () {
  $catalog = terrainArenaCatalog();

  expect($catalog->getArenaFor(terrainBattle(['battleArena' => 'arena.region']))->name)->toBe('Region')
    ->and($catalog->resolveArenaVariant('arena.region', null)->isMissingVariant)->toBeFalse();
});

it('carries the cell\'s encounter terrain with the map\'s arena', function () {
  $table = terrainArenaTable(['battleArena' => 'arena.region']);

  expect($table->resolveBattleArenaAt('Wolves', new Vector2(3, 1))->settings)->toBe(['battleArena' => 'arena.region', 'battleTerrain' => 'woodland'])
    ->and($table->resolveBattleArenaAt('Wolves', new Vector2(5, 0))->settings)->toBe(['battleArena' => 'arena.region'])
    ->and($table->resolveBattleArenaAt('Wolves', null)->settings)->toBe(['battleArena' => 'arena.region'])
    ->and(terrainArenaTable([])->resolveBattleArenaAt('Wolves', new Vector2(0, 0)))
    ->scope->toBe('default')->settings->toBe(['battleTerrain' => 'grassland']);
});

it('fights a troop with a scene of its own there as authored, above every map rule', function () {
  $table = terrainArenaTable(['battleArena' => 'arena.region', 'battleArenas' => [['area' => 'vale', 'battleArena' => 'arena.vale']]]);

  expect($table->resolveBattleArenaAt('Lake Wyrm', new Vector2(4, 0))->settings)->toBe(['battleArena' => 'arena.marsh']);
});

it('picks an area\'s arena, a terrain\'s arena outright, and an area terrain\'s above both', function () {
  $table = terrainArenaTable(['battleArena' => 'arena.region', 'battleArenas' => [
    ['area' => 'vale', 'battleArena' => 'arena.vale'],
    ['terrain' => 'wetland', 'battleArena' => 'arena.marsh'],
    ['terrain' => 'grassland', 'battleArena' => 'arena.region'],
    ['area' => 'vale', 'terrain' => 'wetland', 'battleArena' => 'arena.vale-marsh'],
  ]]);

  expect($table->resolveBattleArenaAt('Wolves', new Vector2(3, 0)))
    ->scope->toBe('area vale')->settings->toBe(['battleArena' => 'arena.vale', 'battleTerrain' => 'woodland'])
    ->and($table->resolveBattleArenaAt('Wolves', new Vector2(4, 0)))
    ->scope->toBe('area vale, wetland')->settings->toBe(['battleArena' => 'arena.vale-marsh'])
    ->and($table->resolveBattleArenaAt('Wolves', new Vector2(1, 0)))
    ->scope->toBe('terrain grassland')->settings->toBe(['battleArena' => 'arena.region']);
});

it('leaves out and names arena rules that could never hold', function () {
  $table = terrainArenaTable(['battleArenas' => [
    ['area' => 'nowhere', 'battleArena' => 'arena.vale'],
    ['terrain' => 'snowfield', 'battleArena' => 'arena.vale'],
    ['battleArena' => 'arena.vale'],
    ['terrain' => 'woodland', 'battleArena' => 'arena.woods'],
    ['terrain' => 'woodland', 'battleArena' => 'arena.vale'],
  ]]);

  expect($table->arenaRules)->toHaveCount(1)
    ->and($table->ignored)->toHaveCount(4)
    ->and(implode("\n", $table->ignored))->toContain('nowhere')->toContain('snowfield')->toContain('neither')->toContain('repeats rule 4');
});

it('measures which arena art covers every battle a map can start and names the missing variants', function () {
  $table = terrainArenaTable(['battleArena' => 'arena.region', 'battleArenas' => [['area' => 'vale', 'battleArena' => 'arena.vale']]]);
  $open = CollisionType::NONE->value;
  $solid = CollisionType::SOLID->value;
  $collisions = [[$open, $open, $open, $open, $open, $open], [$open, $open, $open, $open, $solid, $open]];
  $coverage = EncounterArenaCoverage::measure($table, $collisions, terrainArenaCatalog());

  expect($coverage->findMissingVariants())->toBe([
    ['arena' => 'arena.vale', 'terrain' => 'wetland', 'scopes' => ['area vale'], 'cells' => 1],
  ]);
  expect(EncounterArenaCoverage::measure($table, $collisions, terrainArenaCatalog(), static fn(int $x, int $y): bool => $x !== 4)
    ->findMissingVariants())->toBe([]);
});
