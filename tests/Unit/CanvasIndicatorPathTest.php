<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasIndicator, CanvasIndicatorKind, CanvasIndicatorReferences,
  CanvasImage, CanvasComposite, CanvasCompositeOperation, CanvasGroup, CanvasColorTransform, CanvasRectangle,
  CanvasImagePreflight, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, RendererPresentation};
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererSessionConfig, RendererGridConfig, RendererEvent};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function pathIndicator(?array $path = ['body-group','body']): CanvasIndicator {
  return new CanvasIndicator('outline','body',CanvasIndicatorKind::OUTLINE,new CanvasRectangle(4,4,8,8),1,
    PresentationColor::rgb(220,180,40),200,$path);
}
function pathFixture(bool $composite = false, float $x = 0): PresentationCanvas {
  $bounds=new CanvasRectangle(4,4,8,8);$body=new CanvasImage('body','test-sprite.png',$bounds,120);
  $surface=new CanvasComposite('body',8,8,$bounds,[new CanvasCompositeOperation(['type'=>'fill',
    'destination'=>['x'=>0,'y'=>0,'width'=>8,'height'=>8],
    'brush'=>['type'=>'solid','color'=>['kind'=>'rgb','r'=>40,'g'=>70,'b'=>100]]])],120);
  $invert=new CanvasColorTransform([[-1,0,0],[0,-1,0],[0,0,-1]],[1,1,1]);
  $local=new PresentationCanvas(32,32,$composite?[]:[$body],composites:$composite?[$surface]:[]);
  return new PresentationCanvas(32,32,[new CanvasImage('effect','test-sprite.png',$bounds,180)],groups:[
    new CanvasGroup('body-group',$x,0,$local,120,colorTransform:$invert),
    new CanvasGroup('outline-group',0,0,new PresentationCanvas(32,32,indicators:[pathIndicator()]),200,colorTransform:$invert)]);
}
function pathPresenter(bool $paths = true, bool $compositeIndicators = true): array {
  $caps=[RendererSessionConfig::GRAPHICAL_CANVAS,RendererSessionConfig::CANVAS_GROUPS,RendererSessionConfig::CANVAS_GROUP_COLOR,
    RendererSessionConfig::CANVAS_REGION_BUDGET,RendererSessionConfig::CANVAS_COMPOSITING,
    ...($paths?[RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH]:[]),
    ...($compositeIndicators?[RendererSessionConfig::CANVAS_COMPOSITE_INDICATORS]:[])];
  $transport=new FakeRendererTransport();$client=new RendererClient($transport);
  $client->start(new RendererSessionConfig('Paths',__DIR__.'/../Fixtures/Renderer',protocol:RendererProtocolVersion::V2,requiredCapabilities:$caps));
  $transport->batches[]=[RendererEvent::fromJson(json_encode(['protocol'=>2,'type'=>'ready','capabilities'=>$caps]))];$client->pump();
  return [new RendererPresentation($client,new RendererGridConfig(2,1)),$transport];
}

it('preserves local indicator wire packets and refuses missing local associations',function(){
  expect(pathIndicator(null)->toArray())->not->toHaveKey('imagePath');
  expect(fn()=>new PresentationCanvas(32,32,indicators:[pathIndicator(null)]))->toThrow(InvalidArgumentException::class,'same frame');
});

it('copies qualified paths without caller aliases and requires the declared leaf',function(){
  $group='body-group';$source=[&$group,'body'];$indicator=pathIndicator($source);$group='changed';$source[1]='wrong';
  expect($indicator->imagePath)->toBe(['body-group','body'])->and($indicator->toArray()['imagePath'])->toBe(['body-group','body']);
});

it('refuses malformed qualified path descriptors',function(array $path){
  expect(fn()=>pathIndicator($path))->toThrow(InvalidArgumentException::class);
})->with([
  'empty' => [[]], 'leaf only' => [['body']], 'too deep' => [array_fill(0,10,'body')],
  'wrong leaf' => [['body-group','wrong']], 'numeric id' => [['body-group',1]],
  'empty id' => [['','body']], 'control character' => [["bad\nname",'body']],
  'invalid UTF8' => [["\xff",'body']], 'keyed path' => [['named'=>'body-group','leaf'=>'body']],
]);

it('resolves image and composite leaves without changing the body-effect-outline paint order',function(bool $composite){
  $canvas=pathFixture($composite);$outline=$canvas->groups[1]->canvas;$source=CanvasIndicatorReferences::resolve($canvas,$outline,$outline->indicators[0]);
  expect($source instanceof CanvasComposite)->toBe($composite)->and($source->id)->toBe('body')
    ->and($canvas->groups[0]->layer)->toBe(120)->and($canvas->images[0]->layer)->toBe(180)->and($canvas->groups[1]->layer)->toBe(200)
    ->and($canvas->groups[1]->colorTransform->toArray())->toBe($canvas->groups[0]->colorTransform->toArray());
  CanvasIndicatorReferences::assertCanvases([$canvas]);
})->with([false,true]);

it('resolves the same leaf ID through the chosen branch and rejects absent groups or leaves',function(string $failure){
  $one=new CanvasImage('body','test-sprite.png',new CanvasRectangle(1,1,2,2));
  $two=new CanvasImage($failure==='leaf'?'other':'body','test-sprite.png',new CanvasRectangle(9,9,2,2));
  $first=new CanvasGroup('first',0,0,new PresentationCanvas(32,32,[$one]));
  $second=new CanvasGroup('second',0,0,new PresentationCanvas(32,32,[$two]));
  $indicator=pathIndicator([$failure==='group'?'absent':'second','body']);
  $local=new PresentationCanvas(32,32,indicators:[$indicator]);$root=new PresentationCanvas(32,32,groups:[$first,$second,new CanvasGroup('markers',0,0,$local)]);
  if($failure!=='none'){expect(fn()=>CanvasIndicatorReferences::assertCanvases([$root]))->toThrow(InvalidArgumentException::class);}
  else{expect(CanvasIndicatorReferences::resolve($root,$local,$indicator))->toBe($two);}
})->with(['none','group','leaf']);

it('keeps unique group scopes and refuses two siblings with the same path identity',function(){
  $empty=new PresentationCanvas(32,32);
  expect(fn()=>new PresentationCanvas(32,32,groups:[new CanvasGroup('same',0,0,$empty),new CanvasGroup('same',1,0,$empty)]))
    ->toThrow(InvalidArgumentException::class,'unique IDs');
});

it('preflights the complete reference graph before optional artwork filtering and adds no duplicate region',function(){
  $root=pathFixture();$without=new PresentationCanvas(32,32,$root->images,groups:[$root->groups[0],
    new CanvasGroup('outline-group',0,0,new PresentationCanvas(32,32),200,colorTransform:$root->groups[1]->colorTransform)]);
  $assets=__DIR__.'/../Fixtures/Renderer';
  expect(CanvasImagePreflight::inspectCanvases([$root],$assets))->toBe(CanvasImagePreflight::inspectCanvases([$without],$assets));
  $missing=new PresentationCanvas(32,32,groups:[$root->groups[1]]);
  expect(fn()=>CanvasImagePreflight::inspectCanvasResources([$missing],$assets,static fn()=>false))->toThrow(InvalidArgumentException::class,'existing group');
});

it('requires explicit path negotiation and resolves composite-indicator capability at the qualified leaf',function(bool $composite){
  [$presenter,$transport]=pathPresenter(false);$before=$transport->sent;
  expect(fn()=>$presenter->prepareCanvas(pathFixture($composite)))->toThrow(RendererProtocolException::class,'canvas_indicator_image_path')
    ->and($transport->sent)->toBe($before);
  [$presenter,$transport]=pathPresenter(true,false);$before=$transport->sent;
  if($composite){expect(fn()=>$presenter->prepareCanvas(pathFixture(true)))->toThrow(RendererProtocolException::class,'canvas_composite_indicators')
    ->and($transport->sent)->toBe($before);}
  else{expect($presenter->presentCanvas(pathFixture()))->toBeTrue();}
})->with([false,true]);

it('declares capability prerequisites without making qualified paths mandatory for old consumers',function(){
  expect(fn()=>new RendererSessionConfig('Paths',getTestTemporaryDirectory(),protocol:RendererProtocolVersion::V2,
    requiredCapabilities:[RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH]))->toThrow(InvalidArgumentException::class,'canvas_groups');
  $config=new RendererSessionConfig('Legacy',getTestTemporaryDirectory(),protocol:RendererProtocolVersion::V2);
  expect($config->requiredCapabilities)->toBe([])->and($config->getNegotiableCapabilities())->toContain(RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH);
});

it('replaces or retires retained groups atomically without stale associations or HUD replays',function(){
  [$presenter,$transport]=pathPresenter();$first=pathFixture();expect($presenter->presentCanvas($first))->toBeTrue();$transport->acknowledgeLatestFrame($presenter);
  $before=$transport->sent;$missing=new PresentationCanvas(32,32,groups:[$first->groups[1]]);
  expect(fn()=>$presenter->presentCanvas($missing))->toThrow(InvalidArgumentException::class,'existing group')->and($transport->sent)->toBe($before);
  expect($presenter->presentCanvas(pathFixture(false,1.5)))->toBeTrue();$transport->acknowledgeLatestFrame($presenter);
  expect($presenter->presentCanvas(new PresentationCanvas(32,32)))->toBeTrue();$transport->acknowledgeLatestFrame($presenter);
  $frames=RetainedFrameState::replay($transport->sent);
  expect($frames[0]['canvas']['groups'][1]['canvas']['indicators'][0]['imagePath'])->toBe(['body-group','body'])
    ->and($frames[1]['canvas']['groups'][0]['origin']['x'])->toBe(1.5)->and($frames[2]['canvas']['groups']??[])->toBe([]);
});

it('prefixes only identities changed by overlay composition including paths inside descendant canvases',function(){
  $root=pathFixture();$before=$root->toArray();$stack=PresentationCanvas::composeOverlay(new PresentationCanvas(32,32),$root,'review-');
  $indicator=$stack->groups[1]->canvas->indicators[0];
  expect($stack->groups[0]->id)->toBe('review-body-group')->and($indicator->imageId)->toBe('body')
    ->and($indicator->imagePath)->toBe(['review-body-group','body'])->and($root->toArray())->toBe($before)
    ->and($stack->groups[0]->canvas)->toBe($root->groups[0]->canvas);
  CanvasIndicatorReferences::assertCanvases([$stack]);
  expect(CanvasIndicatorReferences::resolve($stack,$stack->groups[1]->canvas,$indicator))->toBe($root->groups[0]->canvas->images[0]);
  $flat=new PresentationCanvas(32,32,$root->images,[pathIndicator()],[ ],groups:$root->groups);
  $stack=PresentationCanvas::composeOverlay(null,$flat,'flat-');
  expect($stack->indicators[0]->imagePath)->toBe(['flat-body-group','body'])->and($stack->indicators[0]->imageId)->toBe('body');
  CanvasIndicatorReferences::assertCanvases([$stack]);
});

it('promotes qualified roots through whole-field placement while preserving clips and parent treatment',function(){
  $root=pathFixture();$clip=new CanvasRectangle(0,0,28,28);$group=CanvasGroup::wrapRoot('field',-2.5,1.25,$root,8,$clip);
  $parent=new PresentationCanvas(32,32,groups:[$group]);$indicator=$group->canvas->groups[1]->canvas->indicators[0];
  expect($indicator->imagePath)->toBe(['field','body-group','body'])->and($group->x)->toBe(-2.5)->and($group->clipRect)->toBe($clip)
    ->and($group->canvas->groups[1]->colorTransform)->toBe($root->groups[1]->colorTransform);
  CanvasIndicatorReferences::assertCanvases([$parent]);
  $prefixed=PresentationCanvas::composeOverlay(null,$parent,'scene-');
  expect($prefixed->groups[0]->canvas->groups[1]->canvas->indicators[0]->imagePath)->toBe(['scene-field','body-group','body']);
  CanvasIndicatorReferences::assertCanvases([$prefixed]);
});

it('accepts the eighth group level and refuses a ninth without changing the original root',function(){
  $path=['g1','g2','g3','g4','g5','g6','g7','g8','body'];$indicator=pathIndicator($path);
  $node=new PresentationCanvas(32,32,[new CanvasImage('body','test-sprite.png',new CanvasRectangle(1,1,2,2))]);
  for($i=8;$i>=1;$i--){$node=new PresentationCanvas(32,32,groups:[new CanvasGroup('g'.$i,0,0,$node)]);}
  $root=new PresentationCanvas(32,32,indicators:[$indicator],groups:$node->groups);CanvasIndicatorReferences::assertCanvases([$root]);
  expect(fn()=>CanvasGroup::wrapRoot('ninth',0,0,$root))->toThrow(InvalidArgumentException::class,'1..8')
    ->and($indicator->imagePath)->toBe($path);
});


it('refuses cross-layer path capture before prefixed or unprefixed overlay roots merge', function (bool $reverse, string $prefix): void {
  $source = pathFixture();
  $donor = new PresentationCanvas(32, 32, groups: [$source->groups[0]]);
  $orphan = new PresentationCanvas(32, 32, groups: [$source->groups[1]]);
  $before = [$donor->toArray(), $orphan->toArray()];
  expect(fn() => PresentationCanvas::composeOverlay($reverse ? $orphan : $donor, $reverse ? $donor : $orphan, $prefix))
    ->toThrow(InvalidArgumentException::class, 'existing group');
  expect([$donor->toArray(), $orphan->toArray()])->toBe($before);
})->with([false, true], ['', 'overlay-']);

it('refuses orphan references even when overlay composition returns one unprefixed root directly', function (): void {
  $source = pathFixture(); $orphan = new PresentationCanvas(32, 32, groups: [$source->groups[1]]);
  expect(fn() => PresentationCanvas::composeOverlay(null, $orphan))->toThrow(InvalidArgumentException::class, 'existing group');
  $nested = new PresentationCanvas(32, 32, groups: [CanvasGroup::wrapRoot('placement', 0, 0, $orphan)]);
  expect(fn() => PresentationCanvas::composeOverlay(new PresentationCanvas(32, 32), $nested))
    ->toThrow(InvalidArgumentException::class, 'existing group');
});
