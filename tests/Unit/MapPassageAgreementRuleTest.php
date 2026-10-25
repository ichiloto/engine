<?php

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapPassageAgreementRule;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once dirname(__DIR__) . '/Support/Rendering/GraphicalSpriteFixtures.php';

/**
 * What a map shows agrees with what it lets the player do. Synthetic maps,
 * tilesets and replaceable artwork only: a field of ground tiles (2816), water
 * (2048), an unclassified upper tile (5) and one drawn above characters (6),
 * read against glyph collision where '#' is solid and every other glyph walkable.
 */

beforeEach(function () {
    $this->oldDebug = new ReflectionClass(Debug::class)->getStaticProperties();
    $this->root = createTestDirectory('ichiloto-passage-');
    Debug::configure(['log_directory' => $this->root . '/logs']);
    // Tiny sheets fit the RPG Maker layouts at two pixels a tile; appearance never reads their pixels.
    writeTestPng($this->root . '/Graphics/A1.png', 32, 24);
    writeTestPng($this->root . '/Graphics/A2.png', 32, 24);
    writeTestPng($this->root . '/Graphics/B.png', 32, 32);
});
afterEach(function () {
    foreach ($this->oldDebug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
});

/** Paints opaque blocks, in field cells of the drawn image, into otherwise transparent art. */
function writePassageArt(string $path, int $columns, int $rows, array $blocks): void
{
    if (!function_exists('imagecreatefrompng')) { test()->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
    $image = imagecreatetruecolor($columns * 48, $rows * 48);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $columns * 48 - 1, $rows * 48 - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    foreach ($blocks as [$x, $y, $width, $height]) {
        imagefilledrectangle($image, (int) ($x * 48), (int) ($y * 48), (int) (($x + $width) * 48) - 1, (int) (($y + $height) * 48) - 1,
            imagecolorallocatealpha($image, 120, 90, 60, 0));
    }
    imagepng($image, $path);
}

/**
 * @param list<string> $glyphs Terminal rows; '#' is solid.
 * @param list<string> $tiles Rows of tile identities, one per cell.
 * @return list<\Ichiloto\Engine\Field\Validation\MapValidationIssue>
 */
function inspectPassage(string $root, array $glyphs, array $tiles, array $data = [], array $resources = [], array $appearance = [],
    string $eventRows = '', array $pieces = []): array
{
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', implode("\n", $glyphs))]);
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png',
        'B' => 'Graphics/B.png'], 'above' => [6], 'appearance' => $appearance, 'pieces' => $pieces]);
    $graphics = new MapGraphics($tileset, [new MapTileLayer('ground', 0, 'synthetic', implode("\n", $tiles))], owners: ['ground' => 'terrain']);
    $occupancy = MapCollisionResolver::resolveMap($layers, $data, ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE,
        'M' => CollisionType::NONE]);
    $eventLayer = array_map(mb_str_split(...), explode("\n", $eventRows === '' ? implode("\n", array_map(
        static fn(string $row): string => str_repeat(' ', mb_strlen($row)), $glyphs)) : $eventRows));
    $catalog = $resources === [] ? null : FieldPresentationCatalog::fromArray(['resources' => $resources], $root);
    return MapCompletenessValidator::validatePassageAgreement('test/field', $data, $eventLayer, $layers, $occupancy, $graphics, $root, $catalog);
}

/** @return array<string, list<array{int, int}>> Cells by rule. */
function groupPassageCells(array $issues): array
{
    $cells = [];
    foreach ($issues as $issue) { $cells[$issue->rule] = [...$cells[$issue->rule] ?? [], ...$issue->cells]; }
    return $cells;
}

function fillTiles(int $width, int $height, int $tile = 2816, array $cells = []): array
{
    $rows = array_fill(0, $height, array_fill(0, $width, $tile));
    foreach ($cells as [$x, $y, $id]) { $rows[$y][$x] = $id; }
    return array_map(static fn(array $row): string => implode(' ', $row), $rows);
}

it('reports a solid cell that shows only walkable ground, and not one that shows water or a barrier', function () {
    $glyphs = ['     ', '  #  ', '     '];
    $issues = inspectPassage($this->root, $glyphs, fillTiles(5, 3));
    expect(groupPassageCells($issues))->toBe([MapPassageAgreementRule::INVISIBLE_WALL => [[2, 1]]])
        ->and($issues[0]->message)->toContain('invisible wall');
    expect(inspectPassage($this->root, $glyphs, fillTiles(5, 3, cells: [[2, 1, 2048]])))->toBe([]);
    // The tileset says what an upper tile shows; until it does, the cell is not judged.
    expect(inspectPassage($this->root, $glyphs, fillTiles(5, 3, cells: [[2, 1, 5]])))->toBe([])
        ->and(inspectPassage($this->root, $glyphs, fillTiles(5, 3, cells: [[2, 1, 5]]), appearance: ['barrier' => [5]]))->toBe([])
        ->and(groupPassageCells(inspectPassage($this->root, $glyphs, fillTiles(5, 3, cells: [[2, 1, 5]]), appearance: ['ground' => ['B']])))
            ->toBe([MapPassageAgreementRule::INVISIBLE_WALL => [[2, 1]]]);
});

it('reads art drawn over the player as more than ground, and never as an obstacle to walk through', function () {
    // Tile 6 is drawn above characters, so by default it is overhead art such as a roof.
    expect(inspectPassage($this->root, ['     ', '  #  ', '     '], fillTiles(5, 3, cells: [[2, 1, 6]])))->toBe([])
        ->and(inspectPassage($this->root, ['     ', '     ', '     '], fillTiles(5, 3, cells: [[2, 1, 6]])))->toBe([]);
});

it('leaves the doors a stamped piece paints to the doorway rule', function () {
    // Tile 5 shows a barrier: a door painted in a wall. The piece declares its middle cell a door entered on the opening.
    $glyphs = ['     ', '     '];
    $tiles = fillTiles(5, 2, cells: [[1, 1, 5], [2, 1, 5], [3, 1, 5]]);
    $gate = ['gate' => ['name' => 'Gate', 'layer' => 'terrain', 'glyphs' => ['   '], 'tiles' => ['ground' => ['5 5 5']]]];
    expect(groupPassageCells(inspectPassage($this->root, $glyphs, $tiles, appearance: ['barrier' => [5]], pieces: $gate)))
        ->toBe([MapPassageAgreementRule::WALK_THROUGH => [[1, 1], [2, 1], [3, 1]]]);
    $gate['gate']['doorway'] = [['x' => 1, 'y' => 0]];
    expect(groupPassageCells(inspectPassage($this->root, $glyphs, $tiles, appearance: ['barrier' => [5]], pieces: $gate)))
        ->toBe([MapPassageAgreementRule::WALK_THROUGH => [[1, 1], [3, 1]]]);
});

it('reports a walkable cell that shows water, or world object art on its ground row', function () {
    $issues = inspectPassage($this->root, ['     ', '     ', '     '], fillTiles(5, 3, cells: [[1, 1, 2048]]));
    expect(groupPassageCells($issues))->toBe([MapPassageAgreementRule::WALK_THROUGH => [[1, 1]]])
        ->and($issues[0]->message)->toContain('water');

    writePassageArt($this->root . '/Graphics/crate.png', 1, 1, [[0, 0, 1, 1]]);
    $crate = ['worldObjects' => [['id' => 'crate', 'anchor' => ['x' => 3, 'y' => 1], 'pivot' => ['x' => .5, 'y' => 1.],
        'sprites2d' => ['asset' => 'Graphics/crate.png']]]];
    $issues = inspectPassage($this->root, ['     ', '     ', '     '], fillTiles(5, 3), $crate);
    expect(groupPassageCells($issues))->toBe([MapPassageAgreementRule::WALK_THROUGH => [[3, 1]]]);
    // Artwork is replaceable: a crate redrawn small enough to stand beside its cell's middle no longer reads as solid.
    writePassageArt($this->root . '/Graphics/crate.png', 1, 1, [[0, .5, .25, .5]]);
    expect(inspectPassage($this->root, ['     ', '     ', '     '], fillTiles(5, 3), $crate))->toBe([]);
});

it('reports a world object whose art leaves most of its solid footprint bare, and follows replaced art', function () {
    $glyphs = ['       ', '       ', ' ##### ', '       '];
    $data = ['worldObjects' => [['id' => 'hall', 'anchor' => ['x' => 3, 'y' => 2], 'pivot' => ['x' => .5, 'y' => 1.],
        'sprites2d' => ['asset' => 'Graphics/hall.png', 'cells' => ['width' => 5, 'height' => 1]],
        'covers' => ['layer' => 'terrain', 'cells' => [[1, 2], [2, 2], [3, 2], [4, 2], [5, 2]], 'tileLayers' => []]]]];
    writePassageArt($this->root . '/Graphics/hall.png', 5, 1, [[2, 0, 1, 1]]);
    $cells = groupPassageCells(inspectPassage($this->root, $glyphs, fillTiles(7, 4), $data));
    expect($cells[MapPassageAgreementRule::UNDER_COVERED])->toBe([[1, 2], [2, 2], [4, 2], [5, 2]])
        ->and($cells[MapPassageAgreementRule::INVISIBLE_WALL])->toBe([[1, 2], [2, 2], [4, 2], [5, 2]]);
    // The artist fits the art to its footprint; nothing stored about the old image needs updating.
    writePassageArt($this->root . '/Graphics/hall.png', 5, 1, [[0, 0, 5, 1]]);
    expect(inspectPassage($this->root, $glyphs, fillTiles(7, 4), $data))->toBe([]);
    // A slender post standing in its one solid cell covers little of it but leaves nothing bare.
    writePassageArt($this->root . '/Graphics/post.png', 1, 1, [[.45, .1, .1, .9]]);
    $post = ['worldObjects' => [['id' => 'post', 'anchor' => ['x' => 3, 'y' => 2], 'pivot' => ['x' => .5, 'y' => 1.],
        'sprites2d' => ['asset' => 'Graphics/post.png'], 'covers' => ['layer' => 'terrain', 'cells' => [[3, 2]], 'tileLayers' => []]]]];
    expect(inspectPassage($this->root, ['       ', '       ', '   #   ', '       '], fillTiles(7, 4), $post))->toBe([]);
});

it('leaves art above an object\'s top ground row alone as ordinary overhang', function () {
    // A tree: one solid trunk cell on its ground row and a crown two cells high over walkable ground behind it.
    writePassageArt($this->root . '/Graphics/tree.png', 1, 3, [[0, 0, 1, 3]]);
    $data = ['worldObjects' => [['id' => 'tree', 'anchor' => ['x' => 2, 'y' => 3], 'pivot' => ['x' => .5, 'y' => 1.],
        'sprites2d' => ['asset' => 'Graphics/tree.png', 'cells' => ['width' => 1, 'height' => 3]],
        'covers' => ['layer' => 'terrain', 'cells' => [[2, 3]], 'tileLayers' => []]]]];
    expect(inspectPassage($this->root, ['     ', '     ', '     ', '  #  ', '     '], fillTiles(5, 5), $data))->toBe([]);
    // The same art standing on a walkable ground row is a walk-through there, and only there.
    expect(groupPassageCells(inspectPassage($this->root, ['     ', '     ', '     ', '     ', '     '], fillTiles(5, 5), $data)))
        ->toBe([MapPassageAgreementRule::WALK_THROUGH => [[2, 3]]]);
});

it('skips entrances and painted doorways, which the doorway rule owns', function () {
    // A cave with a walkable doorway on its ground row, entered there.
    writePassageArt($this->root . '/Graphics/cave.png', 3, 2, [[0, 0, 3, 2]]);
    $resources = ['cave' => ['name' => 'Cave', 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => ['asset' => 'Graphics/cave.png',
        'cells' => ['width' => 3, 'height' => 2]], 'doorway' => [['x' => 0, 'y' => 0]]]];
    $data = ['worldObjects' => [['id' => 'cave-1', 'resource' => 'cave', 'anchor' => ['x' => 2, 'y' => 2],
            'covers' => ['layer' => 'terrain', 'cells' => [[1, 1], [2, 1], [3, 1], [1, 2], [2, 2], [3, 2]], 'tileLayers' => []]]],
        'events' => ['M' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'cave/inside', 'spawnPoint' => ['x' => 1, 'y' => 1]]]]];
    $glyphs = ['     ', ' ### ', ' # # ', '     '];
    $events = implode("\n", ['     ', '     ', '  M  ', '     ']);
    expect(inspectPassage($this->root, $glyphs, fillTiles(5, 4), $data, $resources, eventRows: $events))->toBe([]);
    // Without its doorway or entrance, the same walkable cell under the cave's art is a walk-through.
    unset($resources['cave']['doorway'], $data['events']);
    expect(groupPassageCells(inspectPassage($this->root, $glyphs, fillTiles(5, 4), $data, $resources)))
        ->toBe([MapPassageAgreementRule::WALK_THROUGH => [[2, 2]]]);
});

it('does not judge a walkable pocket walled in on every side, as a terminal wall face is drawn', function () {
    $sealed = ['#####', '#   #', '#####', '     '];
    $water = fillTiles(5, 4, cells: [[1, 1, 2048], [2, 1, 2048], [3, 1, 2048]]);
    // Its walls show bare ground and are reported as such; the pocket's own cells are never walked through.
    expect(groupPassageCells(inspectPassage($this->root, $sealed, $water)))->not->toHaveKey(MapPassageAgreementRule::WALK_THROUGH);
    // Opened to the field, the same pocket is walked into and judged.
    expect(groupPassageCells(inspectPassage($this->root, ['#####', '#   #', '## ##', '     '], $water))[MapPassageAgreementRule::WALK_THROUGH])
        ->toBe([[1, 1], [2, 1], [3, 1]]);
});

it('judges nothing on a map without graphics, whose glyphs are its collision', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', " # \n   ")]);
    $occupancy = MapCollisionResolver::resolveMap($layers, [], ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE]);
    expect(MapCompletenessValidator::validatePassageAgreement('test/field', [], [[' ', ' ', ' '], [' ', ' ', ' ']], $layers, $occupancy,
        null, $this->root))->toBe([]);
});
