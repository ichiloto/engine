<?php

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Field\{MapAtlasDefinition,MapAtlasView,MapOverview,MapCategoryCatalog,MapLandmarkCatalog,MapLandmarkCategory,MapLandmarkKind,MapLandmarkDefinition};
use Ichiloto\Engine\UI\Presentation\{MapAtlasPresentation,MapAtlasSnapshot,MapAtlasSize,MenuPresentationCatalog};
use Ichiloto\Engine\Core\Enumerations\MovementHeading;

it('replaces the connection diagram state with the shared geographic atlas state',function() {
    expect(is_subclass_of(\Ichiloto\Engine\Scenes\Game\States\MapState::class,
        \Ichiloto\Engine\Scenes\Game\States\LocalMapState::class))->toBeTrue();
});

it('pans a bounded local camera and offers exactly two distinct graphical ground scales',function() {
    $overview=new MapOverview(array_fill(0,100,array_fill(0,400,' ')));
    $view=new MapAtlasView(100,50);
    $out=$view->getProjection($overview,new Rect(10,10,800,400),true);
    $view->toggleZoom();
    $in=$view->getProjection($overview,new Rect(10,10,800,400),true);
    expect($out->sourceWidth)->toBeGreaterThan($in->sourceWidth)
        ->and($out->sourceWidth/$in->sourceWidth)->toBe(2.0)
        ->and($out->scaleX)->toBeLessThan(\Ichiloto\Engine\Rendering\FieldViewport::TILE_SIZE)
        ->and($in->scaleX)->toBeLessThan(\Ichiloto\Engine\Rendering\FieldViewport::TILE_SIZE);
    for($i=0;$i<1000;$i++) { $view->moveView(1,1); $edge=$view->getProjection($overview,new Rect(10,10,800,400),true); }
    expect($edge->sourceX+$edge->sourceWidth)->toEqualWithDelta(400,0.00001)
        ->and($edge->sourceY+$edge->sourceHeight)->toEqualWithDelta(100,0.00001);
    $view->toggleZoom();
    expect($view->zoomedIn)->toBeFalse();
});

it('keeps viewport raster source and marker geometry aligned as the player pans and changes zoom',function() {
    $overview=new MapOverview(array_fill(0,100,array_fill(0,400,' ')));
    $snapshot=new MapAtlasSnapshot('Synthetic local','map',$overview,[],[],100,50,MovementHeading::EAST);
    $theme=new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1']);
    $view=new MapAtlasView(100.5,50.5);
    $before=MapAtlasPresentation::compose($snapshot,$theme,1350,720,MapAtlasSize::OPENED,mapView:$view);
    $view->moveView(1,0);
    $after=MapAtlasPresentation::compose($snapshot,$theme,1350,720,MapAtlasSize::OPENED,mapView:$view);
    $a=$before->composites[0]->operations[0]->data['source'];
    $b=$after->composites[0]->operations[0]->data['source'];
    expect($b['x'])->toBeGreaterThan($a['x'])->and($b['width'])->toBe($a['width']);
    $view->toggleZoom();
    $zoom=MapAtlasPresentation::compose($snapshot,$theme,1350,720,MapAtlasSize::OPENED,mapView:$view);
    expect($zoom->composites[0]->operations[0]->data['source']['width'])->toBeLessThan($b['width']);
    $unchanged=MapAtlasPresentation::compose($snapshot,$theme,1350,720,MapAtlasSize::OPENED,mapView:$view);
    expect($unchanged->composites)->toEqual($zoom->composites);
});

it('resolves nested maps through current parent landmark references without duplicating coordinates',function() {
    $world=['data'=>['atlas'=>['scope'=>'global'],'events'=>[],
        'worldObjects'=>[['id'=>'town','anchor'=>['x'=>1,'y'=>0]]],
        'landmarks'=>[['id'=>'town','kind'=>'building','anchor'=>['object'=>'town']]]], 'tiles'=>[[' ',' ']]];
    // Resolved anchors use the same synthetic object records as runtime source.
    $world['data']['worldObjects']=[];
    $world['data']['events']=[['marker'=>'A','class'=>\Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger::class,'area'=>['cells'=>[[1,0]]]]];
    $world['data']['landmarks']=[['id'=>'town','kind'=>'entrance','anchor'=>['event'=>'A']]];
    $town=['data'=>['atlas'=>['parent'=>['map'=>'world','landmark'=>'town']]],'tiles'=>[[' ']]];
    $read=fn(string $id)=>['world'=>$world,'town'=>$town][$id];
    expect(MapAtlasDefinition::resolveGlobal('town',$read))->toBe(['map'=>'world','landmark'=>'town'])
        ->and(MapAtlasDefinition::resolveGlobal('world',$read))->toBe(['map'=>'world','landmark'=>null])
        ->and(MapAtlasDefinition::readMap([])->global)->toBeFalse();
    $missing=$town; $missing['data']['atlas']['parent']['landmark']='missing';
    expect(fn()=>MapAtlasDefinition::resolveGlobal('town',fn($id)=>$id==='town' ? $missing : $world))->toThrow(InvalidArgumentException::class);
    $cycle=$town; $cycle['data']['atlas']['parent']['map']='town';
    $cycle['data']['events']=$world['data']['events']; $cycle['data']['landmarks']=$world['data']['landmarks']; $cycle['tiles']=$world['tiles'];
    expect(fn()=>MapAtlasDefinition::resolveGlobal('town',fn($id)=>$cycle))->toThrow(InvalidArgumentException::class);
    expect(fn()=>MapAtlasDefinition::readMap(['atlas'=>['scope'=>'global','parent'=>['map'=>'x','landmark'=>'y']]]))->toThrow(InvalidArgumentException::class);
});

it('validates authored categories and keeps distinct generic pictograms replaceable by project themes',function() {
    $catalog=MapCategoryCatalog::fromArray(['schema'=>MapCategoryCatalog::SCHEMA,'categories'=>[
        'bsa'=>['base'=>'organization','name'=>['message'=>'map_categories.bsa.name','default'=>'BSA']]]]);
    expect($catalog->getBase('bsa'))->toBe(MapLandmarkCategory::ORGANIZATION)->and($catalog->getName('bsa'))->toBe('BSA');
    expect(MapCategoryCatalog::fromArray($catalog->toArray())->toArray())->toBe($catalog->toArray());
    expect(fn()=>new MapCategoryCatalog(['home'=>['base'=>'home','name'=>[]]]))->toThrow(InvalidArgumentException::class);
    expect(fn()=>$catalog->getBase('missing'))->toThrow(InvalidArgumentException::class);
    $markers=[];
    foreach(MapLandmarkCategory::cases() as $i=>$category) {
        $markers[]=['point'=>new \Ichiloto\Engine\Core\Vector2(30+$i*50,30),'category'=>$category,
            'color'=>null];
    }
    $theme=new MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1']);
    foreach($markers as &$marker) { $marker['color']=$theme->colors['accent']; } unset($marker);
    $layer=\Ichiloto\Engine\UI\Presentation\MapAtlasPictograms::createLayer($markers,new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0,0,250,100),$theme,true);
    expect($layer)->not->toBeNull()->and($layer->runs)->not->toBeEmpty();
});


it('fits the whole global geography then allows a closer GPUI level with scrolling',function() {
    $overview=new MapOverview(array_fill(0,100,array_fill(0,400,' ')));
    $view=new MapAtlasView(100,50);
    $full=$view->getProjection($overview,new Rect(0,0,800,400),true,true);
    expect($full->sourceX)->toBe(0.0)->and($full->width)->toBe(800.0)->and($view->moveView(1,0))->toBeFalse();
    $view->toggleZoom();
    $close=$view->getProjection($overview,new Rect(0,0,800,400),true,true);
    expect($close->sourceWidth)->toBe(200.0)->and($view->moveView(1,0))->toBeTrue();
    $pan=$view->getProjection($overview,new Rect(0,0,800,400),true,true);
    expect($pan->sourceX)->toBeGreaterThan($close->sourceX);
    $terminal=$view->getProjection($overview,new Rect(0,0,80,24),false,true);
    expect($terminal->sourceX)->toBe(0.0)->and($terminal->sourceY)->toBe(0.0)->and($view->moveView(1,1))->toBeFalse();
});

it('validates source-owned Terminal place and floorplan views independently of map scope or artwork',function() {
    expect(MapAtlasDefinition::readMap([])->terminalView)->toBe('floorplan')
        ->and(MapAtlasDefinition::readMap(['atlas'=>['scope'=>'global']])->terminalView)->toBe('places')
        ->and(MapAtlasDefinition::readMap(['atlas'=>['terminalView'=>'places']])->terminalView)->toBe('places')
        ->and(MapAtlasDefinition::readMap(['atlas'=>['terminalView'=>'floorplan','parent'=>['map'=>'town','landmark'=>'door']]])->parentMap)->toBe('town');
    foreach(['art',false,null,1] as $value) {
        expect(fn()=>MapAtlasDefinition::readMap(['atlas'=>['terminalView'=>$value]]))->toThrow(InvalidArgumentException::class);
    }
});
