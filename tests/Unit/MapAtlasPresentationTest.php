<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\{MapLandmarkDefinition, MapLandmarkKind, MapOverview};
use Ichiloto\Engine\UI\Presentation\{MapAtlasPresentation, MapAtlasSize, MapAtlasSnapshot, MenuPresentationCatalog};

function makeGraphicalAtlasSnapshot(int $x = 0, int $y = 0): MapAtlasSnapshot
{
    return new MapAtlasSnapshot('Synthetic harbour','map',new MapOverview(array_fill(0,8,array_fill(0,20,'.'))),[],[
        ['definition'=>new MapLandmarkDefinition('door',MapLandmarkKind::ENTRANCE,CellArea::fromCells([[19,7]])),
            'name'=>null,'accessible'=>false],
        ['definition'=>new MapLandmarkDefinition('shop',MapLandmarkKind::BUILDING,CellArea::fromCells([[0,0]])),
            'name'=>str_repeat('Long readable shop name ',48),'accessible'=>true],
    ],$x,$y,MovementHeading::EAST);
}

it('uses one whole-map raster in every atlas size and keeps player motion separate', function (MapAtlasSize $size, bool $custom) {
    $theme = new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1',...($custom ? [
        'colors'=>['accent'=>[31,213,70]],'atlas'=>['compactWidth'=>240,'compactHeight'=>180,
            'largeWidth'=>480,'largeHeight'=>360,'frameRole'=>'panel','markerSize'=>14,'margin'=>24]] : [])]);
    $before = MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,$size,1);
    $after = MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(19,7),$theme,1350,720,$size,1,1);
    expect($before->getReferencedWorldIds())->toBe(['map'])
        ->and($before->composites)->toHaveCount(1)->and($after->composites)->toEqual($before->composites);
    $raster = $before->composites[0];
    expect($raster->width)->toBeLessThanOrEqual(1024)->and($raster->height)->toBeLessThanOrEqual(1024)
        ->and($raster->destination->width/$raster->destination->height)->toEqualWithDelta(20/8,.001);
    $players = array_column($before->indicators,null,'id');
    $moved = array_column($after->indicators,null,'id');
    expect($players['atlas-player']->bounds)->not->toEqual($moved['atlas-player']->bounds);
    if ($size === MapAtlasSize::OPENED) {
        $legends = array_column($before->textLayers,null,'id');
        $later = array_column($after->textLayers,null,'id');
        expect($legends)->toHaveKey('atlas-legend')->and($legends['atlas-focus']->runs)->not->toEqual($later['atlas-focus']->runs);
    }
})->with([MapAtlasSize::COMPACT,MapAtlasSize::LARGE,MapAtlasSize::OPENED])->with([false,true]);

it('retains coordinate presentation when any atlas capability is absent', function () {
    $composer = new MapAtlasPresentation();
    foreach (MapAtlasPresentation::CAPABILITIES as $missing) {
        expect($composer->getCanvas(makeGraphicalAtlasSnapshot(),'/tmp',fn($cap)=>$cap!==$missing,
            1350,720,MapAtlasSize::COMPACT))->toBeNull();
    }
});

it('fits minimum authored corner sizes and thin source maps without losing their overview', function () {
    $theme = new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','atlas'=>[
        'compactWidth'=>96,'compactHeight'=>96,'largeWidth'=>96,'largeHeight'=>96]]);
    foreach ([new MapOverview([array_fill(0,10000,'.')]),new MapOverview(array_fill(0,1000,['.']))] as $overview) {
        $snapshot = new MapAtlasSnapshot('Thin map','map',$overview,[],[],0,0,MovementHeading::SOUTH);
        expect(MapAtlasPresentation::compose($snapshot,$theme,640,480,MapAtlasSize::COMPACT)->composites)->toHaveCount(1);
    }
});


it('keeps wide-map corner frames close to their content and gives default markers a contrasting halo', function () {
    $theme = new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','showInputHints'=>false]);
    $canvas = MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,MapAtlasSize::LARGE);
    $areas = $canvas->protectedAreas;
    $frame = $areas[0];
    expect($frame->height)->toBeLessThan(300);
    $markers = array_column($canvas->indicators,null,'id');
    expect($markers)->toHaveKey('atlas-player-halo')->toHaveKey('atlas-marker-halo-0')
        ->and($markers['atlas-player-halo']->color)->toEqual($theme->colors['background'])
        ->and(count($markers))->toBeLessThanOrEqual(2048);
});


it('names only the focused location in the opened atlas across graphical window sizes', function () {
    $source = makeGraphicalAtlasSnapshot();
    $landmarks = [];
    for ($index=0;$index<40;$index++) { $landmarks[] = [...$source->landmarks[0],'name'=>'Place '.$index,'accessible'=>true]; }
    $snapshot = new MapAtlasSnapshot($source->name,$source->worldId,$source->overview,[],
        $landmarks,0,0,MovementHeading::NORTH);
    $theme = new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','showInputHints'=>false]);
    foreach ([[1350,720],[900,540]] as [$width,$height]) {
        $first = MapAtlasPresentation::compose($snapshot,$theme,$width,$height,MapAtlasSize::OPENED,0);
        $count = MapAtlasPresentation::getLegendPageSize($first);
        $next = MapAtlasPresentation::compose($snapshot,$theme,$width,$height,MapAtlasSize::OPENED,$count);
        $legend = array_column($next->textLayers,null,'id')['atlas-focus'];
        $text=implode(' ',array_column($legend->runs,'text'));
        expect($text)->toContain('Place 1')->not->toContain('Place 0')->not->toContain('Place 2')
            ->and($count)->toBe(1);
    }
});


it('keeps opened map navigation discoverable when optional theme hints are hidden',function() {
    $theme=new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','showInputHints'=>false]);
    $view=new \Ichiloto\Engine\Field\MapAtlasView(0,0);
    $canvas=MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,MapAtlasSize::OPENED,mapView:$view);
    $controls=array_column($canvas->textLayers,null,'id')['atlas-hints'] ?? null;
    expect($controls)->not->toBeNull();
    $text=implode(' ',array_column($controls->runs,'text'));
    expect($text)->toContain('World map')->toContain('Zoom')->toContain('Scroll')->toContain('Close');
    $corner=MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,MapAtlasSize::COMPACT);
    expect(array_column($corner->textLayers,'id'))->not->toContain('atlas-hints');
});

it('fills corner maps to a thin rim without location text or menu spacing',function(MapAtlasSize $size, bool $custom) {
    $theme=new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','showInputHints'=>true,
        ...($custom ? ['metrics'=>['panelPadding'=>48,'sectionGap'=>24],
            'colors'=>['edge'=>[83,170,91]],'atlas'=>['compactWidth'=>240,'compactHeight'=>180,
                'largeWidth'=>480,'largeHeight'=>360,'frameRole'=>'panel']] : [])]);
    foreach([[20,8],[8,20],[12,12]] as [$mapWidth,$mapHeight]) {
        $snapshot=new MapAtlasSnapshot('Current place name','map',new MapOverview(array_fill(0,$mapHeight,array_fill(0,$mapWidth,'.'))),
            [],[],0,0,MovementHeading::SOUTH);
        $canvas=MapAtlasPresentation::compose($snapshot,$theme,1350,720,$size);
        $frame=$canvas->protectedAreas[0];
        $map=$canvas->composites[0]->destination;
        expect(array_column($canvas->textLayers,'id'))->not->toContain('atlas-title')->not->toContain('atlas-hints')
            ->not->toContain('atlas-legend');
        foreach([$map->x-$frame->x,$map->y-$frame->y,
            $frame->x+$frame->width-$map->x-$map->width,$frame->y+$frame->height-$map->y-$map->height] as $inset) {
            expect($inset)->toBeGreaterThanOrEqual(1)->toBeLessThan(1.51);
        }
        expect($map->width*$map->height/($frame->width*$frame->height))->toBeGreaterThan(.94)
            ->and($frame->x+$frame->width)->toEqual(1350-$theme->atlas->margin)
            ->and($frame->y)->toEqual($theme->atlas->margin)
            ->and($frame->width)->toBeLessThanOrEqual($size===MapAtlasSize::LARGE ? $theme->atlas->largeWidth : $theme->atlas->compactWidth)
            ->and($frame->height)->toBeLessThanOrEqual($size===MapAtlasSize::LARGE ? $theme->atlas->largeHeight : $theme->atlas->compactHeight)
            ->and($map->width/$map->height)->toEqualWithDelta($mapWidth/$mapHeight,.001);
    }
})->with([MapAtlasSize::COMPACT,MapAtlasSize::LARGE])->with([false,true]);

it('exposes exactly the atlas icon roles for shared theme authoring',function() {
    $categories=new \Ichiloto\Engine\Field\MapCategoryCatalog(['guild'=>['name'=>['message'=>'map_categories.guild.name','default'=>'Guild'],'base'=>'organization']]);
    $roles=MapAtlasPresentation::listIconRoles($categories);
    expect($roles)->toContain(MapAtlasPresentation::PLAYER_ICON_ROLE,'map.category.guild','map.category.inn','map.category.home',
            MapAtlasPresentation::OBJECTIVE_ICON_ROLE,MapAtlasPresentation::OBJECTIVE_ROUTE_ICON_ROLE)
        ->and($roles)->toHaveCount(13)->and(array_unique($roles))->toBe($roles);
    foreach(MapLandmarkKind::cases() as $kind) { expect($roles)->toContain($kind->getIconRole()); }
    expect(\Ichiloto\Engine\Field\MapCategoryCatalog::getIconRole('guild'))->toBe('map.category.guild')
        ->and(fn()=>\Ichiloto\Engine\Field\MapCategoryCatalog::getIconRole('../invalid'))->toThrow(InvalidArgumentException::class);
});

it('makes corner geography and its rim translucent without an opaque backing or dimming markers',function() {
    $theme=new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','atlas'=>['cornerOpacity'=>.4]]);
    $corner=MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,MapAtlasSize::COMPACT);
    expect($corner->composites[0]->opacity)->toBe(.4)->and($corner->images)->toBe([]);
    $rim=array_filter($corner->textLayers,fn($layer)=>str_starts_with($layer->id,'atlas-rim'));
    expect($rim)->not->toBeEmpty();
    foreach($rim as $layer) { expect($layer->opacity)->toBe(.4); }
    expect(array_column($corner->textLayers,'id'))->not->toContain('atlas-frame')->not->toContain('menu-background');
    $opened=MapAtlasPresentation::compose(makeGraphicalAtlasSnapshot(),$theme,1350,720,MapAtlasSize::OPENED);
    expect($opened->composites[0]->opacity)->toBe(1.0);
    foreach([-1,1.1,INF,NAN,'0.5'] as $value) {
        expect(fn()=>new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1','atlas'=>['cornerOpacity'=>$value]]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('cycles compact larger and hidden independently of opened map zoom',function() {
    $size=MapAtlasSize::COMPACT;
    $seen=[];
    for($step=0;$step<4;$step++) { $seen[]=$size->value; $size=$size->getNextCornerSize(); }
    expect($seen)->toBe(['compact','large','off','compact'])
        ->and(MapAtlasSize::getCornerSize('opened'))->toBe(MapAtlasSize::COMPACT)
        ->and(MapAtlasSize::getCornerSize('off'))->toBe(MapAtlasSize::OFF)
        ->and(fn()=>MapAtlasSize::OPENED->getNextCornerSize())->toThrow(LogicException::class)
        ->and((new MapAtlasPresentation())->getCanvas(makeGraphicalAtlasSnapshot(),'/tmp',fn()=>true,1350,720,MapAtlasSize::OFF))->toBeNull();
});
