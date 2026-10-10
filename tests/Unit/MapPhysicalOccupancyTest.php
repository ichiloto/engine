<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;

it('retains every final CollisionType without deriving physical meaning from glyphs', function () {
    $types = array_values(array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH));
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', str_repeat('#', count($types)))]);
    $data = ['occupancy' => [$types]];
    $occupancy = MapCollisionResolver::resolveMap($layers, $data, ['#' => CollisionType::SOLID]);
    expect($occupancy->collisionGrid)->toBe([array_column($types, 'value')])
        ->and($occupancy->exportDeclaration())->toBe([$types])
        ->and($data)->toBe(['occupancy' => [$types]]);
});

it('captures legacy layer precedence exactly for an explicit migration without writing map data', function () {
    $layers = new MapLayerSet([
        new MapLayer('terrain', 1, false, 'terrain', ";~8 ?e\n     x"),
        new MapLayer('buildings', 2, false, 'buildings', " axb z\n      "),
        new MapLayer('fixtures', 3, false, 'fixtures', "  \e[33mi\e[0m   \n      "),
        new MapLayer('detail', 4, true, 'detail', "xxxxxx\nxxxxxx"),
    ]);
    $dictionary = [';' => CollisionType::ENCOUNTER, '~' => CollisionType::SOLID,
        8 => CollisionType::SAVE_POINT, '?' => CollisionType::SAVE_POINT, ' ' => CollisionType::NONE,
        'x' => CollisionType::COUNTER, 'a' => CollisionType::PASS_THROUGH, 'b' => CollisionType::NONE,
        'buildings' => ['x' => CollisionType::NONE], 'fixtures' => ['i' => CollisionType::PASS_THROUGH]];
    $expected = [[7, 1, 0, 0, 6, 1], [0, 0, 0, 0, 0, 10]];
    $data = ['name' => 'Synthetic'];
    $snapshot = MapCollisionResolver::resolveLegacyOccupancy($layers, $dictionary);
    expect($snapshot->collisionGrid)->toBe($expected)
        ->and(MapCollisionResolver::resolveMap($layers, $data, $dictionary)->collisionGrid)->toBe($expected)
        ->and(MapCollisionResolver::resolveLayers($layers, $dictionary))->toBe($expected)
        ->and($data)->toBe(['name' => 'Synthetic']);

    $migrated = $data + ['occupancy' => $snapshot->exportDeclaration()];
    $edited = new MapLayerSet([new MapLayer('appearance', 1, false, 'appearance', "......\n######")]);
    expect(MapCollisionResolver::resolveMap($edited, $migrated, ['.' => CollisionType::ITEM, '#' => CollisionType::NONE])->collisionGrid)
        ->toBe($expected)
        ->and(MapCollisionResolver::resolveMap($edited, $data, ['.' => CollisionType::ITEM, '#' => CollisionType::NONE])->collisionGrid)
        ->not->toBe($expected);
});

it('preserves flat legacy ANSI numeric and transliterated glyph passage on ragged rows', function () {
    $text = "\n\e[32m8\e[0m\u{00e9};\n?x";
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'legacy', $text)], legacy: true);
    $dictionary = [8 => CollisionType::ITEM, 'e' => CollisionType::NONE, ';' => CollisionType::ENCOUNTER,
        '?' => CollisionType::SAVE_POINT, 'x' => CollisionType::PASS_THROUGH];
    $expected = [[], [4, 0, 7], [6, 1]];
    $manager = new ReflectionClass(MapManager::class)->newInstanceWithoutConstructor();
    $snapshot = MapCollisionResolver::resolveLegacyOccupancy($layers, $dictionary);
    expect($snapshot->collisionGrid)->toBe($expected)
        ->and($manager->generateCollisionMap(explode("\n", $text), $dictionary))->toBe($expected)
        ->and($manager->generateCollisionMap($layers->getComposedGrid(), $dictionary))->toBe($expected)
        ->and($manager->generateLayerCollisionMap($layers, $dictionary))->toBe($expected)
        ->and(MapCollisionResolver::resolveMap($layers, ['occupancy' => $snapshot->exportDeclaration()])->collisionGrid)->toBe($expected);
});

it('keeps ragged and empty legacy rows without inventing padding cells', function (string $text, array $rows) {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'legacy', $text)], legacy: true);
    $occupancy = new MapPhysicalOccupancy($rows, $layers);
    expect(array_map(count(...), $occupancy->collisionGrid))->toBe(array_map(count(...), $layers->getComposedGrid()))
        ->and($occupancy->exportDeclaration())->toBe($rows);
})->with([
    'ragged with an empty row' => ["\nxx\nx", [[], [CollisionType::NONE, CollisionType::COUNTER], [CollisionType::SOLID]]],
    'empty legacy grid' => ['', [[]]],
]);

it('preserves matching ragged layered maps through the same physical contract', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "xx\nx"),
        new MapLayer('fixtures', 2, false, 'fixtures', " i\n ")]);
    $rows = [[CollisionType::NONE, CollisionType::COUNTER], [CollisionType::ENCOUNTER]];
    expect(MapCollisionResolver::resolveMap($layers, ['occupancy' => $rows])->collisionGrid)->toBe([[0, 10], [7]]);
});

it('detaches declared row and cell references and exports an independent migration value', function () {
    $type = CollisionType::NONE;
    $row = [&$type];
    $rows = [&$row];
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', 'x')]);
    $occupancy = new MapPhysicalOccupancy($rows, $layers);
    $exported = $occupancy->exportDeclaration();
    $type = CollisionType::SOLID;
    $row[] = CollisionType::COUNTER;
    $exported[0][0] = CollisionType::NPC;
    expect($occupancy->collisionGrid)->toBe([[0]])
        ->and($occupancy->exportDeclaration())->toBe([[CollisionType::NONE]]);
});

it('does not consult a glyph dictionary when explicit physical data is declared', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', 'x'),
        new MapLayer('detail', 2, true, 'detail', 'x')]);
    $dictionary = ['x' => 'invalid', 'detail' => ['x' => CollisionType::NONE]];
    expect(MapCollisionResolver::resolveMap($layers, ['occupancy' => [[CollisionType::NONE]]], $dictionary)->collisionGrid)->toBe([[0]])
        ->and(fn() => MapCollisionResolver::resolveMap($layers, [], $dictionary))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed declared physical data instead of falling back to walkable glyphs', function (mixed $rows, string $reason) {
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "..\n..")]);
    expect(fn() => MapCollisionResolver::resolveMap($layers, ['occupancy' => $rows], ['.' => CollisionType::NONE]))
        ->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'null declaration' => [null, 'zero-based list of rows'],
    'string declaration' => ['..', 'zero-based list of rows'],
    'boolean declaration' => [false, 'zero-based list of rows'],
    'empty declaration' => [[], 'must have 2 rows'],
    'missing row' => [[[CollisionType::NONE, CollisionType::NONE]], 'must have 2 rows'],
    'extra row' => [[[CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE], []], 'must have 2 rows'],
    'sparse row indexes' => [[1 => [CollisionType::NONE, CollisionType::NONE], 2 => [CollisionType::NONE, CollisionType::NONE]], 'zero-based list of rows'],
    'unknown wrapper' => [['rows' => []], 'zero-based list of rows'],
    'string row' => [['..', [CollisionType::NONE, CollisionType::NONE]], 'row 0 must be a zero-based list'],
    'null row' => [[null, [CollisionType::NONE, CollisionType::NONE]], 'row 0 must be a zero-based list'],
    'sparse cell indexes' => [[[0 => CollisionType::NONE, 2 => CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]], 'row 0 must be a zero-based list'],
    'short row' => [[[CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]], 'row 0 must be 2 tiles wide'],
    'wide row' => [[[CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE, CollisionType::NONE]], 'row 1 must be 2 tiles wide'],
    'integer cell' => [[[0, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]], 'cell 0, 0 must be a CollisionType case'],
    'string cell' => [[[CollisionType::NONE, 'SOLID'], [CollisionType::NONE, CollisionType::NONE]], 'cell 1, 0 must be a CollisionType case'],
    'null cell' => [[[CollisionType::NONE, CollisionType::NONE], [null, CollisionType::NONE]], 'cell 0, 1 must be a CollisionType case'],
    'boolean cell' => [[[CollisionType::NONE, CollisionType::NONE], [false, CollisionType::NONE]], 'cell 0, 1 must be a CollisionType case'],
    'float cell' => [[[CollisionType::NONE, CollisionType::NONE], [1.0, CollisionType::NONE]], 'cell 0, 1 must be a CollisionType case'],
    'pass-through cell' => [[[CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::PASS_THROUGH]], 'must be resolved; PASS_THROUGH'],
]);
