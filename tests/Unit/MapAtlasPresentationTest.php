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
        expect($legends)->toHaveKey('atlas-legend')->and($legends['atlas-legend']->runs)->not->toEqual($later['atlas-legend']->runs);
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
        $legend = array_column($next->textLayers,null,'id')['atlas-legend'];
        $text=implode(' ',array_column($legend->runs,'text'));
        expect($text)->toContain('Place 1')->not->toContain('Place 0')->not->toContain('Place 2')
            ->and($count)->toBe(1);
    }
});
