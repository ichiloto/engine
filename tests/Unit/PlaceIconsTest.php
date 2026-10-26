<?php

use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\PlaceTypeCatalog;
use Ichiloto\Engine\Field\Validation\MapEventResolver;
use Ichiloto\Engine\Field\Validation\MapPlaceIconRule;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/** Synthetic place types: a walled settlement and a lone building. */
function syntheticPlaceTypes(): array
{
    return ['schema' => PlaceTypeCatalog::SCHEMA, 'types' => [
        'burgh' => ['name' => ['message' => 'place_types.burgh.name', 'default' => 'Burgh']],
        'lodge' => ['name' => ['message' => 'place_types.lodge.name', 'default' => 'Lodge']],
        'glade' => ['name' => ['message' => 'place_types.glade.name', 'default' => 'Glade']],
    ]];
}

/** A synthetic world tileset: a 3x2 walled settlement and a 1x2 lone building, each with its way in at the bottom. */
function syntheticPlaceTileset(array $icons = ['burgh' => 'walls', 'lodge' => 'hut']): array
{
    return ['name' => 'Synthetic World', 'sheets' => ['E' => 'Graphics/synthetic.png'], 'pieces' => [
        'walls' => ['name' => 'Walls', 'layer' => 'buildings', 'glyphs' => ['#^#', '#∩#'],
            'tiles' => ['objects' => ['801 802 803', '809 810 811']]],
        'hut' => ['name' => 'Hut', 'layer' => 'buildings', 'glyphs' => ['^', '∩'], 'tiles' => ['objects' => ['812', '820']]],
        'trail' => ['name' => 'Trail', 'layer' => 'buildings', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['objects' => '5888']],
        'mark' => ['name' => 'Mark', 'layer' => 'buildings', 'glyphs' => ['x']],
    ], ...($icons === [] ? [] : [Tileset::PLACE_ICONS => $icons])];
}

/**
 * Inspects a synthetic 8x5 world with an entrance at 3,3 into `site/yard`, the given pieces stamped by their tiles
 * at their top-left cells, and their glyphs drawn unless the copy says `tilesOnly`.
 *
 * @param list<array{0: string, 1: int, 2: int, 3?: bool}> $stamps
 */
function inspectPlaceIcons(array $stamps, ?string $type, array $icons = ['burgh' => 'walls', 'lodge' => 'hut']): array
{
    $tileset = Tileset::fromArray('synthetic', syntheticPlaceTileset($icons));
    $tiles = array_fill(0, 5, array_fill(0, 8, '0'));
    $glyphs = array_fill(0, 5, array_fill(0, 8, ' '));
    foreach ($stamps as $stamp) {
        [$id, $x, $y] = $stamp;
        $piece = $tileset->pieces[$id];
        foreach ($piece->tiles['objects'] as $dy => $row) {
            foreach ($row as $dx => $tile) { $tiles[$y + $dy][$x + $dx] = $tile; }
        }
        foreach (($stamp[3] ?? false) ? [] : $piece->glyphs as $dy => $row) {
            foreach ($row as $dx => $glyph) { $glyphs[$y + $dy][$x + $dx] = $glyph; }
        }
    }
    $graphics = new MapGraphics($tileset, [new MapTileLayer('objects', 0, 'objects',
        implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $tiles)))]);
    $layers = new MapLayerSet([new MapLayer('buildings', 0, false, 'synthetic',
        implode("\n", array_map(static fn(array $row): string => implode('', $row), $glyphs)))]);
    $eventLayer = array_fill(0, 5, array_fill(0, 8, ' '));
    $eventLayer[3][3] = 'Y';
    $data = ['events' => ['Y' => ['class' => TransferPlayerTrigger::class,
        'data' => ['destinationMap' => 'site/yard', 'spawnPoint' => ['x' => 1, 'y' => 1]]]]];
    $events = MapEventResolver::inspect($data, $eventLayer, 'test/world')->events;

    return MapPlaceIconRule::inspect('test/world', $events, $layers, $graphics, PlaceTypeCatalog::fromArray(syntheticPlaceTypes()),
        ['test/world' => null, 'site/yard' => $type]);
}

it('reads authored place types and a map\'s place type, and loads a project without them as none', function () {
    $types = PlaceTypeCatalog::fromArray(syntheticPlaceTypes());
    expect($types->getIds())->toBe(['burgh', 'lodge', 'glade'])
        ->and($types->getName('lodge'))->toBe('Lodge')
        ->and($types->hasType('cave'))->toBeFalse()
        ->and($types->toArray())->toBe(syntheticPlaceTypes())
        ->and(PlaceTypeCatalog::getNameMessageKey('lodge'))->toBe('place_types.lodge.name')
        ->and(PlaceTypeCatalog::readMapPlaceType(['name' => 'Yard']))->toBeNull()
        ->and(PlaceTypeCatalog::readMapPlaceType(['placeType' => 'lodge']))->toBe('lodge')
        ->and(fn() => PlaceTypeCatalog::readMapPlaceType(['placeType' => 'Big Town']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $types->getName('cave'))->toThrow(InvalidArgumentException::class, "Unknown place type 'cave'");

    $root = sys_get_temp_dir() . '/place-types-' . bin2hex(random_bytes(4));
    mkdir($root . '/Data', 0777, true);
    try {
        expect(PlaceTypeCatalog::load($root)->getIds())->toBe([]);
        file_put_contents($root . '/' . PlaceTypeCatalog::FILE, '<?php return ' . var_export(syntheticPlaceTypes(), true) . ';');
        expect(PlaceTypeCatalog::load($root)->getIds())->toBe(['burgh', 'lodge', 'glade']);
    } finally {
        @unlink($root . '/' . PlaceTypeCatalog::FILE);
        @rmdir($root . '/Data');
        @rmdir($root);
    }
});

it('refuses malformed place types', function (array $types, string $message) {
    expect(fn() => PlaceTypeCatalog::fromArray(['schema' => PlaceTypeCatalog::SCHEMA, 'types' => $types]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'bad id' => [['Big Town' => ['name' => ['message' => 'k', 'default' => 'A']]], 'stable lowercase id'],
    'unknown key' => [['a' => ['name' => ['message' => 'k', 'default' => 'A'], 'icon' => 'x']], 'stable lowercase id'],
    'no name' => [['a' => []], 'stable lowercase id'],
]);

it('gives each place type the piece its tileset shows for it', function () {
    $tileset = Tileset::fromArray('synthetic', syntheticPlaceTileset());
    expect($tileset->placeIcons)->toBe(['burgh' => 'walls', 'lodge' => 'hut'])
        ->and($tileset->getPlaceIcon('lodge'))->toBe($tileset->pieces['hut'])
        ->and($tileset->getPlaceIcon('glade'))->toBeNull()
        ->and(Tileset::fromArray('synthetic', syntheticPlaceTileset([]))->placeIcons)->toBe([]);
});

it('refuses a place icon that is not one of the tileset\'s pieces stamped whole with tiles', function (array $icons, string $message) {
    expect(fn() => Tileset::fromArray('synthetic', syntheticPlaceTileset($icons)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown piece' => [['lodge' => 'tower'], "lodge shows 'tower', which is not one of its pieces"],
    'connected piece' => [['lodge' => 'trail'], 'lodge shows piece trail, which is not stamped whole with tiles'],
    'no tiles' => [['lodge' => 'mark'], 'lodge shows piece mark, which is not stamped whole with tiles'],
    'bad type id' => [['Lone Hut' => 'hut'], "'Lone Hut' is not a stable place type id"],
    'a list' => [['hut'], 'placeIcons must be keyed by place type id'],
]);

it('accepts an entrance under the icon of its place\'s type, in its tiles and its terminal glyphs', function () {
    // The walls' way in is at 1,1 of the piece; the hut's at 0,1.
    expect(inspectPlaceIcons([['walls', 2, 2]], 'burgh'))->toBe([])
        ->and(inspectPlaceIcons([['hut', 3, 2]], 'lodge'))->toBe([]);
});

it('reports an entrance whose icon is not its place type\'s, naming what stands there', function () {
    $issues = inspectPlaceIcons([['walls', 2, 2]], 'lodge');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->rule)->toBe(MapPlaceIconRule::ICON)
        ->and($issues[0]->marker)->toBe('Y')
        ->and($issues[0]->cells)->toBe([[3, 3]])
        ->and($issues[0]->message)->toBe("Entrance 'Y' leads to site/yard, a place of type Lodge, whose icon is Hut; the Walls stands over it.")
        ->and(inspectPlaceIcons([], 'lodge')[0]->message)->toEndWith('whose icon is Hut; no place icon stands over it.')
        // Tiles alone do not make the icon: the terminal shows the place too.
        ->and(inspectPlaceIcons([['hut', 3, 2, true]], 'lodge')[0]->message)->toEndWith('its tiles show Hut but its terminal glyphs do not.');
});

it('follows the tileset when a type is given another icon', function () {
    // Walls is no longer any type's icon, so it is no place icon at all.
    expect(inspectPlaceIcons([['walls', 2, 2]], 'burgh', ['burgh' => 'hut', 'lodge' => 'hut'])[0]->message)
        ->toBe("Entrance 'Y' leads to site/yard, a place of type Burgh, whose icon is Hut; no place icon stands over it.")
        ->and(inspectPlaceIcons([['hut', 3, 2]], 'burgh', ['burgh' => 'hut', 'lodge' => 'hut']))->toBe([]);
});

it('reports a type the tileset gives no icon, and an icon over a way into a map naming no type', function () {
    $glade = inspectPlaceIcons([['hut', 3, 2]], 'glade');
    $untyped = inspectPlaceIcons([['hut', 3, 2]], null);
    expect($glade[0]->rule)->toBe(MapPlaceIconRule::ICON)
        ->and($glade[0]->message)->toBe("Entrance 'Y' leads to site/yard, a place of type Glade, but tileset Synthetic World shows no icon for Glade.")
        ->and($untyped[0]->rule)->toBe(MapPlaceIconRule::UNTYPED)
        ->and($untyped[0]->message)->toBe("Entrance 'Y' shows the Hut icon but leads to site/yard, which names no place type.")
        // A way into a map naming no type under no icon is a plain way through.
        ->and(inspectPlaceIcons([], null))->toBe([])
        // A tileset giving no icons judges nothing; a type the project lacks is its map's to report.
        ->and(inspectPlaceIcons([['walls', 2, 2]], 'lodge', []))->toBe([])
        ->and(inspectPlaceIcons([['walls', 2, 2]], 'fort'))->toBe([]);
});
