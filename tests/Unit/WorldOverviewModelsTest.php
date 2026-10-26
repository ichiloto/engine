<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeBudget, CanvasCompositeOperation, CanvasImage,
    CanvasIndicator, CanvasIndicatorKind, CanvasRectangle, CanvasWorldSubject, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, PresentationSpritePivot};

function makeWorldOverviewSubject(): CanvasWorldSubject
{
    return new CanvasWorldSubject('still', 2, 3, 2, 1, 96, 48, new PresentationSpritePivot(.25,.75),
        [new CanvasImage('image', 'Graphics/still.png', new CanvasRectangle(0,0,96,48))], 5);
}

it('round-trips bounded map-owned still groups through the world raster operation', function () {
    $subject = makeWorldOverviewSubject();
    expect(CanvasWorldSubject::fromArray($subject->toArray()))->toEqual($subject);
    $op = new CanvasCompositeOperation(['type' => 'world', 'worldId' => 'map',
        'destination' => ['x'=>0,'y'=>0,'width'=>64,'height'=>32], 'subjects' => [$subject]]);
    expect(new CanvasCompositeOperation($op->data)->data)->toBe($op->data)
        ->and($op->getAssets())->toBe(['Graphics/still.png']);
    $composite = new CanvasComposite('atlas',64,32,new CanvasRectangle(10,10,128,64),[$op],10);
    expect(CanvasCompositeBudget::getWork([$composite]))->toBe(64*32*18);
    $canvas = new PresentationCanvas(200,100, composites: [$composite], indicators: [
        new CanvasIndicator('player','atlas',CanvasIndicatorKind::OUTLINE,new CanvasRectangle(20,20,8,8),4,PresentationColor::rgb(255,200,50),11),
    ]);
    expect($canvas->toArray()['indicators'][0]['imageId'])->toBe('atlas');
});

it('refuses duplicate or excessive overview subjects and oversized rasters', function () {
    $base = ['type'=>'world','worldId'=>'map','destination'=>['x'=>0,'y'=>0,'width'=>64,'height'=>32],'subjects'=>[]];
    foreach ([['subjects'=>[makeWorldOverviewSubject(),makeWorldOverviewSubject()]],
        ['subjects'=>array_fill(0,129,makeWorldOverviewSubject())],
        ['destination'=>['x'=>0,'y'=>0,'width'=>1025,'height'=>1]], ['worldId'=>''], ['subjects'=>null]] as $change) {
        expect(fn() => new CanvasCompositeOperation(array_replace($base,$change)))->toThrow(InvalidArgumentException::class);
    }
    $data = makeWorldOverviewSubject()->toArray();
    foreach ([['x'=>-1],['widthCells'=>INF],['widthCells'=>0],['quarterTurns'=>4],['time'=>1]] as $change) {
        expect(fn() => CanvasWorldSubject::fromArray(array_replace($data,$change)))->toThrow(InvalidArgumentException::class);
    }
});

it('bounds overview work to one active map raster across the complete candidate frame', function () {
    $make = fn($id)=>new CanvasComposite($id,16,16,new CanvasRectangle(0,0,16,16),[
        new CanvasCompositeOperation(['type'=>'world','worldId'=>'map','destination'=>['x'=>0,'y'=>0,'width'=>16,'height'=>16],'subjects'=>[]])]);
    $first = new PresentationCanvas(32,32,composites:[$make('one')]);
    $second = new PresentationCanvas(32,32,composites:[$make('two')]);
    expect(fn()=>\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget::assertCanvases([$first,$second]))
        ->toThrow(InvalidArgumentException::class);
});
