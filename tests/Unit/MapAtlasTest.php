<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapOverview;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\MapAtlasTerminal;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

it('fits the complete Terminal source with stable margins and correctly placed heading markers', function (int $width, int $height) {
    $tiles = array_fill(0, 8, array_fill(0, 20, '.'));
    $overview = new MapOverview($tiles);
    $before = $overview->getTerminalRaster($width, $height);
    foreach ([[0, 0], [19, 0], [0, 7], [19, 7], [10, 4]] as [$x, $y]) {
        $rows = MapAtlasTerminal::renderMap($overview, [], new Vector2($x, $y), MovementHeading::EAST, $width, $height);
        $point = $overview->getTerminalProjection($width, $height)->projectCell($x, $y);
        $plain = TerminalText::visibleSymbols(TerminalText::stripAnsi(TerminalText::formatStyles($rows[(int) floor($point->y)])));
        expect($plain[(int) floor($point->x)])->toBe('@');
        foreach ($rows as $row) { expect(TerminalText::displayWidth($row))->toBe($width); }
    }
    expect($overview->getTerminalRaster($width, $height))->toBe($before);
})->with([[30, 12], [80, 24], [100, 30], [8, 3]]);

it('draws thin maps and leaves real ragged gaps without inventing cells', function () {
    foreach ([[array_fill(0, 10000, '.')], array_fill(0, 1000, ['.'])] as $tiles) {
        $overview = new MapOverview($tiles);
        expect(implode('', array_map(fn($row) => implode('', $row), $overview->getTerminalRaster(20, 10))))->not->toContain('·');
    }
    $ragged = new MapOverview([['A', 'A'], ['B']]);
    $raster = $ragged->getTerminalRaster(8, 4);
    $point = $ragged->getTerminalProjection(8, 4)->projectCell(1, 1);
    expect($raster[(int) floor($point->y)][(int) floor($point->x)])->toBe(' ');
});

it('bounds raster storage across repeated resizes and keeps wide glyphs from shifting markers', function () {
    $overview = new MapOverview([['界', 'A']]);
    foreach ([[10, 4], [20, 8], [30, 12], [10, 4]] as [$width, $height]) {
        foreach ($overview->getTerminalRaster($width, $height) as $row) {
            expect(TerminalText::displayWidth(implode('', $row)))->toBe($width);
        }
    }
    $cache = new ReflectionProperty($overview, 'terminalRasters')->getValue($overview);
    expect($cache)->toHaveCount(2);
    expect(fn() => $overview->getTerminalRaster(513, 10))->toThrow(InvalidArgumentException::class);
});

it('shares replaceable palette and frame roles with configurable atlas sizes across themes', function () {
    $default = new MenuPresentationCatalog('/tmp', ['schema' => 'ichiloto.menu/1']);
    $custom = new MenuPresentationCatalog('/tmp', ['schema' => 'ichiloto.menu/1',
        'colors' => ['accent' => [31, 213, 70]],
        'atlas' => ['compactWidth' => 240, 'compactHeight' => 180, 'largeWidth' => 480, 'largeHeight' => 360,
            'markerSize' => 14, 'margin' => 24, 'frameRole' => 'panel']]);
    expect($default->atlas->compactWidth)->toBe(256)->and($custom->atlas->compactWidth)->toBe(240)
        ->and($custom->atlas->largeWidth)->toBe(480)->and($custom->atlas->frameRole)->toBe('panel')
        ->and($custom->colors['accent'])->not->toEqual($default->colors['accent']);
    foreach ([['compactWidth' => 0], ['largeWidth' => 200], ['markerSize' => 2], ['margin' => -1],
        ['frameRole' => 'unsupported'], ['compactWidth' => 200.5], ['unknown' => 1]] as $data) {
        expect(fn() => new MenuPresentationCatalog('/tmp', ['schema' => 'ichiloto.menu/1', 'atlas' => $data]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('uses physical occupancy for the Terminal schematic and never reproduces decorative lettering',function() {
    $tiles=[['R','O','O','F'],['界','W','A','L'],[' ',' ',' ',' ']];
    $overview=new MapOverview($tiles,[[1,1,1,1],[1,0,0,1],[1,0,0,1]]);
    $raster=$overview->getTerminalRaster(16,8);
    $projection=$overview->getTerminalProjection(16,8);
    foreach([[1,0,'-'],[1,1,' ']] as [$x,$y,$expected]) {
        $point=$projection->projectCell($x,$y);
        expect($raster[(int)floor($point->y)][(int)floor($point->x)])->toBe($expected);
    }
    $plain=implode('',array_map(fn($row)=>implode('',$row),$raster));
    expect($plain)->not->toContain('R')->not->toContain('界')->not->toContain('·')->toContain('-');
});


it('draws blank rooms with ASCII wall runs and chevron doors while global places use blocks',function() {
    $tiles=array_fill(0,5,array_fill(0,7,'decorative'));
    $occupancy=array_fill(0,5,array_fill(0,7,1));
    for($y=1;$y<4;$y++) { for($x=1;$x<6;$x++) { $occupancy[$y][$x]=0; } }
    $overview=new MapOverview($tiles,$occupancy);
    $projection=\Ichiloto\Engine\Field\MapProjection::createViewport(7,5,new \Ichiloto\Engine\Core\Rect(0,0,7,5),3.5,2.5,1,1);
    $raster=$overview->getTerminalRaster(7,5,$projection);
    expect(array_map(fn($row)=>implode('',$row),$raster))->toBe(['+-----+','|     |','|     |','|     |','+-----+']);
    $landmark=['definition'=>new \Ichiloto\Engine\Field\MapLandmarkDefinition('door',\Ichiloto\Engine\Field\MapLandmarkKind::ENTRANCE,
        \Ichiloto\Engine\Core\CellArea::fromCells([[3,4]])),'name'=>null,'accessible'=>true];
    foreach([false=>'v',true=>'■'] as $global=>$glyph) {
        $rows=MapAtlasTerminal::renderMap($overview,[$landmark],new Vector2(3,2),MovementHeading::SOUTH,7,5,-1,$projection,(bool)$global);
        expect(TerminalText::stripAnsi(TerminalText::formatStyles($rows[4])))->toBe('+--'.$glyph.'--+');
        expect(TerminalText::stripAnsi(TerminalText::formatStyles($rows[2])))->toBe('|  @  |');
    }
});

it('removes outdoor collision diagrams and uses one square per place while preserving interior floorplans',function() {
    $tiles=array_fill(0,5,array_fill(0,9,'art'));
    $occupancy=array_fill(0,5,array_fill(0,9,0));
    for($y=1;$y<=3;$y++) { for($x=2;$x<=5;$x++) { $occupancy[$y][$x]=1; } }
    $overview=new MapOverview($tiles,$occupancy);
    $projection=\Ichiloto\Engine\Field\MapProjection::createViewport(9,5,new \Ichiloto\Engine\Core\Rect(0,0,9,5),4.5,2.5,1,1);
    $floorplan=$overview->getTerminalRaster(9,5,$projection);
    $places=$overview->getTerminalRaster(9,5,$projection,false);
    expect(implode('',array_map(implode(...),$floorplan)))->toContain('-')
        ->and(implode('',array_map(implode(...),$places)))->toBe(str_repeat(' ',45))
        ->and($overview->getTerminalRaster(9,5,$projection))->toBe($floorplan);
    $landmarks=[];
    foreach([['door',\Ichiloto\Engine\Field\MapLandmarkKind::ENTRANCE,3,3],
        ['exit',\Ichiloto\Engine\Field\MapLandmarkKind::EXIT,8,2]] as [$id,$kind,$x,$y]) {
        $landmarks[]=['definition'=>new \Ichiloto\Engine\Field\MapLandmarkDefinition($id,$kind,
            \Ichiloto\Engine\Core\CellArea::fromCells([[$x,$y]])),'name'=>null,'accessible'=>true];
    }
    $rows=MapAtlasTerminal::renderMap($overview,$landmarks,new Vector2(0,2),MovementHeading::SOUTH,9,5,-1,$projection,false,false);
    $plain=array_map(fn($row)=>TerminalText::stripAnsi(TerminalText::formatStyles($row)),$rows);
    expect($plain)->toBe(['         ','         ','@       v','   ■     ','         ']);
});
