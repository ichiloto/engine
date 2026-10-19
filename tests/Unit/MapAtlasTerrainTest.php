<?php

use Ichiloto\Engine\Core\{Rect,Vector2};
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\{MapAtlasTerrain,MapAtlasDefinition,MapLayer,MapLayerSet,MapOverview,MapProjection};
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\MapAtlasTerminal;

function createAtlasTerrainFixture(array $declaration, string $ground, ?string $buildings=null): array
{
    $items=[new MapLayer('surface',0,false,'surface.map.php',$ground)];
    if ($buildings!==null) { $items[]=new MapLayer('structures',1,false,'structures.map.php',$buildings); }
    $layers=new MapLayerSet($items);
    $terrain=MapAtlasTerrain::readMap(['atlas'=>['terrain'=>$declaration]],$layers);
    return [$terrain,$layers];
}

it('draws source-owned geography behind markers without copying decorative lettering',function() {
    [$terrain,$layers]=createAtlasTerrainFixture([
        'surface'=>['symbols'=>['g'=>'ground','r'=>'road','w'=>'water','v'=>'vegetation','h'=>'highland']],
        'structures'=>['default'=>'building'],
    ],"wwgrrrg\nwwgvhhg\ngggrrrg","       \n   HOME\n       ");
    $overview=new MapOverview($layers->getComposedGrid(),terrain:$terrain);
    $projection=MapProjection::createViewport(7,3,new Rect(0,0,7,3),3.5,1.5,1,1);
    $raster=$overview->getTerminalRaster(7,3,$projection,false);
    expect(array_map(fn($row)=>implode('',$row),$raster))->toBe(['~~ ::: ','~~ ####','   ::: ']);
    $rows=MapAtlasTerminal::renderMap($overview,[],new Vector2(2,2),MovementHeading::EAST,7,3,
        projection:$projection,global:true,drawOutlines:false);
    expect(TerminalText::stripAnsi(implode("\n",$rows)))->toContain('~~')->toContain('@')->not->toContain('HOME');
    foreach ($rows as $row) { expect(TerminalText::displayWidth($row))->toBe(7); }
});

it('keeps glyph meaning in the authored layer instead of assigning one meaning globally',function() {
    [$terrain]=createAtlasTerrainFixture([
        'surface'=>['symbols'=>['x'=>'water']], 'structures'=>['symbols'=>['x'=>'building']],
    ],'xxx',' x ');
    expect($terrain->getRole(0,0))->toBe('water')->and($terrain->getRole(1,0))->toBe('building')
        ->and($terrain->getRole(2,0))->toBe('water');
    [$other]=createAtlasTerrainFixture(['surface'=>['symbols'=>['x'=>'highland']]],'xxx');
    expect($other->getSymbol(0,0))->toBe('^');
});

it('retains thin roads and bridges when a world map is reduced',function() {
    [$terrain,$layers]=createAtlasTerrainFixture(['surface'=>['symbols'=>['w'=>'water','r'=>'road','b'=>'bridge']]],
        "wwrwwwww\nwwbwwwww\nwwrwwwww\nwwrwwwww");
    $overview=new MapOverview($layers->getComposedGrid(),terrain:$terrain);
    $projection=MapProjection::createViewport(8,4,new Rect(0,0,2,1),4,2,.25,.25);
    expect($overview->getTerminalRaster(2,1,$projection,false))->toBe([['=','~']]);
    [$narrow,$source]=createAtlasTerrainFixture(['surface'=>['symbols'=>['w'=>'water','r'=>'road']]],
        str_repeat('w',79).'r'.str_repeat('w',80));
    $wide=new MapOverview($source->getComposedGrid(),terrain:$narrow);
    expect($wide->getTerminalRaster(1,1,drawOutlines:false))->toBe([[':']]);
});

it('preserves the existing room outlines and blank general ground',function() {
    [$terrain,$layers]=createAtlasTerrainFixture(['surface'=>['symbols'=>['g'=>'ground']]],"ggggg\nggggg\nggggg");
    $overview=new MapOverview($layers->getComposedGrid(),[[1,1,1,1,1],[1,0,0,0,1],[1,1,1,1,1]],$terrain);
    $projection=MapProjection::createViewport(5,3,new Rect(0,0,5,3),2.5,1.5,1,1);
    expect(array_map(fn($row)=>implode('',$row),$overview->getTerminalRaster(5,3,$projection,true)))
        ->toBe(['+---+','|   |','+---+']);
});

it('refuses malformed classifications and missing source references before use',function() {
    foreach ([null,['surface'=>['default'=>'ocean']],['surface'=>['symbols'=>['word'=>'water']]],
        ['surface'=>['symbols'=>null]],['surface'=>['image'=>'water']],['surface'=>['symbols'=>["\e"=>'water']]]] as $bad) {
        expect(fn()=>MapAtlasDefinition::readMap(['atlas'=>['terrain'=>$bad]]))->toThrow(InvalidArgumentException::class);
    }
    expect(fn()=>createAtlasTerrainFixture(['missing'=>['default'=>'water']],'g'))
        ->toThrow(InvalidArgumentException::class,'missing source layer');
});

it('detaches caller references so installed geography cannot change behind its raster cache',function() {
    $role='water';
    $declaration=['surface'=>['symbols'=>['w'=>&$role]]];
    [$terrain]=createAtlasTerrainFixture($declaration,'w');
    $role='obstacle';
    expect($terrain->getSymbol(0,0))->toBe('~')->and($terrain->getRoles())->toBe(['water']);
});

it('leaves graphical atlas composition unchanged when Terminal terrain is classified',function() {
    [$terrain,$layers]=createAtlasTerrainFixture(['surface'=>['symbols'=>['w'=>'water','g'=>'ground']]],"wwgg\nwwgg");
    $plain=new MapOverview($layers->getComposedGrid());
    $classified=new MapOverview($layers->getComposedGrid(),terrain:$terrain);
    $theme=new \Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog('/tmp',['schema'=>'ichiloto.menu/1']);
    $compose=static function(MapOverview $overview) use($theme) {
        $snapshot=new \Ichiloto\Engine\UI\Presentation\MapAtlasSnapshot('Synthetic','map',$overview,[],[],2,1,MovementHeading::EAST);
        return \Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::compose($snapshot,$theme,1350,720,
            \Ichiloto\Engine\UI\Presentation\MapAtlasSize::COMPACT);
    };
    expect($compose($classified))->toEqual($compose($plain));
    expect(fn()=>$terrain->getRegionSymbol(0,0,5000,5000))->toThrow(InvalidArgumentException::class,'16 Mi');
});
