<?php

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Field\WorldObjectResource;

function createWorldObjectResourceSource(array $extra = []): array
{
    return array_replace(['name' => 'Synthetic whole object', 'pivot' => ['x' => .5, 'y' => .875],
        'sprites2d' => ['asset' => 'Graphics/whole.png', 'cells' => ['width' => 3, 'height' => 4], 'layer' => 100],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]],
    ], $extra);
}

it('admits named resources without requiring optional pixels and shares immutable definitions with placements', function () {
    $catalog = FieldPresentationCatalog::fromArray(['resources' => ['whole' => createWorldObjectResourceSource()]], '/missing-synthetic-root');
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'ground', "ggg\nggg")]);
    $entries = [
        ['id' => 'one', 'resource' => 'whole', 'anchor' => ['x' => 0, 'y' => 0]],
        ['id' => 'two', 'resource' => 'whole', 'anchor' => ['x' => 1, 'y' => 1]],
        ['id' => 'inline', 'anchor' => ['x' => 2, 'y' => 0], 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => null],
    ];
    $definitions = WorldObjectDefinition::readMap(['worldObjects' => $entries], $layers, catalog: $catalog);
    $resource = $catalog->getResource('whole');
    expect($definitions[0]->resource)->toBe($resource)->and($definitions[1]->resource)->toBe($resource)
        ->and($definitions[0]->sprites)->toBe($definitions[1]->sprites)
        ->and($definitions[0]->pivot)->toBe($definitions[1]->pivot)
        ->and($definitions[0]->variants)->toBe([])->and($definitions[2]->resource)->toBeNull()
        ->and($resource->name)->toBe('Synthetic whole object')
        ->and($resource->resolveOccupancyStamp($layers, 1, 1))->toBe([['x' => 1, 'y' => 1, 'type' => CollisionType::SOLID]]);
    expect(fn() => $resource->name = 'changed')->toThrow(Error::class)
        ->and(fn() => $catalog->getResource('missing'))->toThrow(InvalidArgumentException::class);
});

it('refuses malformed resource contracts using the existing role pivot path and typed collision boundaries', function (string $invalid) {
    $id = 'whole';
    $source = createWorldObjectResourceSource();
    match ($invalid) {
        'id' => $id = 'Whole',
        'name' => $source['name'] = '',
        'unknown field' => $source['hash'] = 'not-an-identity',
        'path' => $source['sprites2d']['asset'] = '../outside.png',
        'absolute path' => $source['sprites2d']['asset'] = '/outside.png',
        'pivot' => $source['pivot']['y'] = 2,
        'nonfinite pivot' => $source['pivot']['x'] = INF,
        'ui layer' => $source['sprites2d']['layer'] = 1000,
        'null role' => $source['sprites2d'] = null,
        'source dimensions' => $source['sprites2d']['width'] = 400,
        'null stamp' => $source['occupancyStamp'] = null,
        'untyped collision' => $source['occupancyStamp'][0]['type'] = 1,
        'pass through' => $source['occupancyStamp'][0]['type'] = CollisionType::PASS_THROUGH,
        'duplicate cell' => $source['occupancyStamp'][] = $source['occupancyStamp'][0],
        'noninteger offset' => $source['occupancyStamp'][0]['x'] = .5,
        'offset range' => $source['occupancyStamp'][0]['x'] = PHP_INT_MAX,
        'stamp budget' => $source['occupancyStamp'] = array_fill(0, 257, $source['occupancyStamp'][0]),
        'empty doorway' => $source['doorway'] = [],
        'doorway field' => $source['doorway'] = [['x' => 0, 'y' => 0, 'type' => CollisionType::NONE]],
        'fractional doorway' => $source['doorway'] = [['x' => .5, 'y' => 0]],
        'repeated doorway' => $source['doorway'] = [['x' => 0, 'y' => 0], ['x' => 0, 'y' => 0]],
        'doorway budget' => $source['doorway'] = array_map(static fn(int $x): array => ['x' => $x, 'y' => 0], range(0, 16)),
        'unknown doorway entrance' => $source['doorway'] = [['x' => 0, 'y' => 0, 'entrance' => 'behind']],
        'untyped doorway entrance' => $source['doorway'] = [['x' => 0, 'y' => 0, 'entrance' => DoorwayEntrance::FRONT]],
    };
    expect(fn() => FieldPresentationCatalog::fromArray(['resources' => [$id => $source]], '/missing-synthetic-root'))
        ->toThrow(InvalidArgumentException::class);
})->with(['id', 'name', 'unknown field', 'path', 'absolute path', 'pivot', 'nonfinite pivot', 'ui layer', 'null role',
    'source dimensions', 'null stamp', 'untyped collision', 'pass through', 'duplicate cell', 'noninteger offset', 'offset range', 'stamp budget',
    'empty doorway', 'doorway field', 'fractional doorway', 'repeated doorway', 'doorway budget', 'unknown doorway entrance',
    'untyped doorway entrance']);

it('reads a doorway as relative cells with their declared entrance and keeps it absent unless declared', function () {
    $plain = WorldObjectResource::fromArray('whole', createWorldObjectResourceSource());
    $cave = WorldObjectResource::fromArray('cave', createWorldObjectResourceSource(['doorway' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => -1, 'entrance' => 'front']]]));
    expect($plain->doorway)->toBe([])->and($cave->doorway)->toBe([
        ['x' => 0, 'y' => 0, 'entrance' => DoorwayEntrance::ON], ['x' => 1, 'y' => -1, 'entrance' => DoorwayEntrance::FRONT]]);
});

it('detaches recipe references and resolves signed offsets without clipping or mutating physical data', function () {
    $x = -1;
    $type = CollisionType::COUNTER;
    $source = createWorldObjectResourceSource(['occupancyStamp' => [['x' => &$x, 'y' => 0, 'type' => &$type]]]);
    $resource = WorldObjectResource::fromArray('whole', $source);
    $x = 1;
    $type = CollisionType::NONE;
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'ground', "ggg\ng")], legacy: true);
    expect($resource->resolveOccupancyStamp($layers, 1, 0))->toBe([['x' => 0, 'y' => 0, 'type' => CollisionType::COUNTER]])
        ->and(fn() => $resource->resolveOccupancyStamp($layers, 0, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $resource->resolveOccupancyStamp($layers, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and($layers->getComposedGrid())->toBe([['g', 'g', 'g'], ['g']]);
});

it('keeps the unchanged mixed inline and reference object admission limit without truncation', function () {
    $catalog = FieldPresentationCatalog::fromArray(['resources' => ['whole' => createWorldObjectResourceSource()]], '/missing-synthetic-root');
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'ground', 'g')]);
    $entries = [];
    for ($i = 0; $i < WorldObjectDefinition::MAX_OBJECTS; $i++) {
        $entries[] = ['id' => 'object-' . $i, 'anchor' => ['x' => 0, 'y' => 0], ...($i % 2 === 0
            ? ['resource' => 'whole'] : ['pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => null])];
    }
    expect(WorldObjectDefinition::readMap(['worldObjects' => $entries], $layers, catalog: $catalog))->toHaveCount(128);
    $entries[] = ['id' => 'overflow', 'resource' => 'whole', 'anchor' => ['x' => 0, 'y' => 0]];
    expect(fn() => WorldObjectDefinition::readMap(['worldObjects' => $entries], $layers, catalog: $catalog))
        ->toThrow(InvalidArgumentException::class, 'at most 128');
});

it('shares detached animation intent and admits the existing character-sheet role without duplicating source facts', function () {
    $frame = 0;
    $source = createWorldObjectResourceSource(['sprites2d' => ['asset' => 'Graphics/whole.png',
        'animation' => ['columns' => 2, 'frames' => [&$frame, 1], 'fps' => 10]]]);
    $resource = WorldObjectResource::fromArray('whole', $source);
    $frame = 1;
    expect($resource->sprites)->toBeInstanceOf(FieldPoseAnimation::class)->and($resource->sprites->frames)->toBe([0, 1]);
    $sheet = WorldObjectResource::fromArray('sheet', createWorldObjectResourceSource(['sprites2d' => ['sheet' => 'Graphics/$synthetic.png']]));
    expect($sheet->sprites)->toBeInstanceOf(CharacterSheet::class)->and($sheet->sprites->isSingleCharacter)->toBeTrue();
    $alias = $resource;
    $catalog = new FieldPresentationCatalog(resources: ['whole' => &$alias]);
    $alias = $sheet;
    expect($catalog->getResource('whole'))->toBe($resource)
        ->and(fn() => new FieldPresentationCatalog(resources: ['wrong-id' => $resource]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new FieldPresentationCatalog(resources: ['whole' => []]))->toThrow(InvalidArgumentException::class);
});
