<?php
declare(strict_types=1);
use Ichiloto\Engine\Battle\Presentation\{BattleFieldColorState, BattleFieldColorRole, BattleCanvasOwners, BattleFieldColorComposition};
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasRectangle, CanvasIndicator, CanvasIndicatorKind,
  CanvasIndicatorReferences, CanvasGroup, CanvasDrawOrder, CanvasTextBatch, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, PresentationTextRun, StyledPresentationFrame};
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

function compositionPalette(bool $neutral = false): BattleFieldColorState {
  return BattleFieldColorState::fromArray(['field' => null, 'battlers' => $neutral ? null :
    ['matrix'=>[[0,0,0],[0,0,0],[0,0,0]],'offset'=>[0,0,0]], 'effects'=>[]]);
}
function compositionImage(string $id, int $layer): CanvasImage {
  return new CanvasImage($id,'test-sprite.png',new CanvasRectangle(4,4,8,8),$layer);
}
function compositionOutline(int $layer = 200, ?array $path = null): CanvasIndicator {
  return new CanvasIndicator('outline','body',CanvasIndicatorKind::OUTLINE,new CanvasRectangle(4,4,8,8),1,
    PresentationColor::rgb(255,220,80),$layer,$path);
}
function compositionLeaves(PresentationCanvas $canvas): array {
  $ids=[];foreach(CanvasDrawOrder::getItems($canvas) as ['drawing'=>$d]) {
    if($d instanceof CanvasGroup){array_push($ids,...compositionLeaves($d->canvas));}else{$ids[]=$d->id;}
  }return $ids;
}

it('keeps body effect outline order with one body source and independent stroke treatment',function(){
  $body=compositionImage('body',120);$effect=compositionImage('effect',180);$outline=compositionOutline();
  $root=new PresentationCanvas(32,32,[$body,$effect],[$outline]);$before=$root->toArray();$owners=new BattleCanvasOwners(compositionPalette());
  $owners->recordDrawing($body,BattleFieldColorRole::BATTLERS);$owners->recordDrawing($outline,BattleFieldColorRole::BATTLERS);
  $owners->recordDrawing($effect,BattleFieldColorRole::EFFECT,'original');$result=BattleFieldColorComposition::compose($root,$owners);
  expect(compositionLeaves($result))->toBe(['body','effect','outline'])->and($result->groups)->toHaveCount(3)
    ->and($result->groups[0]->canvas->images[0])->toBe($body)->and($result->groups[1]->canvas->images[0])->toBe($effect)
    ->and($result->groups[1]->colorTransform)->toBeNull()->and($result->groups[0]->colorTransform->toArray())->toBe($result->groups[2]->colorTransform->toArray())
    ->and($result->groups[2]->canvas->indicators[0]->bounds)->toBe($outline->bounds)
    ->and(CanvasIndicatorReferences::resolve($result,$result->groups[2]->canvas,$result->groups[2]->canvas->indicators[0]))->toBe($body)
    ->and($result->getOverlayProtection())->toEqual($root->getOverlayProtection())->and($root->toArray())->toBe($before);
});

it('preserves same-layer kind and source order across different treatments including screen UI',function(){
  $body=compositionImage('body',5);$effect=compositionImage('effect',5);$outline=compositionOutline(5);
  $text=new CanvasTextLayer('hud',5,0,0,new RendererGridConfig(1,1,1,1),[new PresentationTextRun(0,0,'X')]);
  $root=new PresentationCanvas(32,32,[$body,$effect],[$outline],[$text]);$owners=new BattleCanvasOwners(compositionPalette());
  foreach([$body,$outline]as$d){$owners->recordDrawing($d,BattleFieldColorRole::BATTLERS);}
  $owners->recordDrawing($effect,BattleFieldColorRole::EFFECT);$owners->recordDrawing($text,BattleFieldColorRole::SCREEN_UI);
  $result=BattleFieldColorComposition::compose($root,$owners);
  expect(compositionLeaves($result))->toBe(['body','effect','outline','hud'])
    ->and(array_column($result->groups,'layer'))->toBe([5,5,5,5]);
});

it('keeps screen UI at its root and authored layers stable as palette runs merge or split',function(){
  $body=compositionImage('body',100);$effect=compositionImage('effect',180);$ui=compositionImage('hud',1003);
  $root=new PresentationCanvas(32,32,[$body,$effect,$ui]);$states=[compositionPalette(),BattleFieldColorState::fromArray([
    'field'=>null,'battlers'=>['matrix'=>[[0,0,0],[0,0,0],[0,0,0]],'offset'=>[0,0,0]],
    'effects'=>[['track'=>'glow','transform'=>['matrix'=>[[0,0,0],[0,0,0],[0,0,0]],'offset'=>[0,0,0]]]]])];$uiGroups=[];
  foreach($states as$state){$owners=new BattleCanvasOwners($state);$owners->recordDrawing($body,BattleFieldColorRole::BATTLERS);
    $owners->recordDrawing($effect,BattleFieldColorRole::EFFECT,'glow');$owners->recordDrawing($ui,BattleFieldColorRole::SCREEN_UI);
    $result=BattleFieldColorComposition::compose($root,$owners);$uiGroups[]=$result->images[0]->toArray();}
  expect($uiGroups[0])->toBe($uiGroups[1])->and($uiGroups[0]['layer'])->toBe(1003);
});

it('retains exact neutral canvas identity and diagnoses missing or conflicting producers',function(){
  $image=compositionImage('body',1);$root=new PresentationCanvas(32,32,[$image]);$owners=new BattleCanvasOwners(compositionPalette(true));
  expect(fn()=>BattleFieldColorComposition::compose($root,$owners))->toThrow(LogicException::class,'explicit source owner');
  $owners->recordDrawing($image,BattleFieldColorRole::BATTLERS);
  expect(BattleFieldColorComposition::compose($root,$owners))->toBe($root)
    ->and(fn()=>$owners->recordDrawing($image,BattleFieldColorRole::FIELD))->toThrow(LogicException::class,'different source owner');
});

it('relocates qualified associations in nested groups while preserving local references and clips',function(){
  $body=compositionImage('body',1);$local=compositionOutline(2);$remote=compositionOutline(3,['source','body']);
  $scale=new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroupScale(1.125,
    new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5,.5));
  $source=new CanvasGroup('source',1,2,new PresentationCanvas(32,32,[$body],[$local]),1,new CanvasRectangle(0,0,20,20),scale:$scale);
  $markers=new CanvasGroup('markers',0,0,new PresentationCanvas(32,32,indicators:[$remote]),3,scale:$scale);
  $root=new PresentationCanvas(32,32,groups:[$source,$markers]);$owners=new BattleCanvasOwners(compositionPalette());
  $owners->recordDrawing($source,BattleFieldColorRole::BATTLERS);$owners->recordDrawing($markers,BattleFieldColorRole::FEEDBACK);
  $result=BattleFieldColorComposition::compose($root,$owners);$movedSource=$result->groups[0]->canvas->groups[0];$movedMarkers=$result->groups[1]->canvas->groups[0];
  expect($movedSource)->toBe($source)->and($movedSource->clipRect)->toBe($source->clipRect)
    ->and(CanvasIndicatorReferences::resolve($result,$movedMarkers->canvas,$movedMarkers->canvas->indicators[0]))->toBe($body)
    ->and($movedMarkers->canvas->indicators[0]->imagePath)->toBe([$result->groups[0]->id,'source','body'])
    ->and($movedMarkers->scale)->toBe($scale)->and($movedSource->scale)->toBe($scale);
  $placed=CanvasGroup::wrapRoot('moving-field',-2.5,1.25,$result);$whole=new PresentationCanvas(32,32,groups:[$placed]);
  CanvasIndicatorReferences::assertCanvases([$whole]);
});

it('charges added groups against the complete depth budget without dropping drawings',function(){
  $root=new PresentationCanvas(32,32,[compositionImage('body',0)]);
  for($i=0;$i<8;$i++){$root=new PresentationCanvas(32,32,groups:[new CanvasGroup('g'.$i,0,0,$root)]);}
  $before=$root->toArray();$owners=new BattleCanvasOwners(compositionPalette());$owners->recordDrawing($root->groups[0],BattleFieldColorRole::BATTLERS);
  expect(fn()=>BattleFieldColorComposition::compose($root,$owners))->toThrow(InvalidArgumentException::class,'eight nested')
    ->and($root->toArray())->toBe($before);
});

it('carries every source owner through glyph batching without blending treatment or screen placement',function(){
  $owners=new BattleCanvasOwners(compositionPalette());$text=[];
  for($i=0;$i<=StyledPresentationFrame::MAX_TEXT_LAYERS;$i++){
    $layer=new CanvasTextLayer('glyph-'.$i,1,$i,0,new RendererGridConfig(1,1,1,1),[new PresentationTextRun(0,0,'x',PresentationColor::rgb(255,255,255))]);
    $text[]=$layer;$owners->recordDrawing($layer,$i===1?BattleFieldColorRole::SCREEN_UI:BattleFieldColorRole::BATTLERS);
  }
  $packed=CanvasTextBatch::compact($text,$owners);expect(count($packed))->toBeLessThanOrEqual(StyledPresentationFrame::MAX_TEXT_LAYERS);
  $glyphs=0;foreach($packed as$layer){$glyphs+=count($layer->runs);$owners->getBatchKey($layer);}
  expect($glyphs)->toBe(count($text))->and(in_array($text[1],$packed,true))->toBeTrue();
});

it('carries independent fill provenance through packed replacements',function(){
  $owners=new BattleCanvasOwners(compositionPalette());$text=[];
  for($i=0;$i<=StyledPresentationFrame::MAX_TEXT_LAYERS;$i++){
    $layer=new CanvasTextLayer('fill-'.$i,1,$i,0,new RendererGridConfig(1,1,1,1),[new PresentationTextRun(0,0,' ',background:PresentationColor::rgb(20,30,40))]);
    $text[]=$layer;$owners->recordDrawing($layer,$i===1?BattleFieldColorRole::SCREEN_UI:BattleFieldColorRole::BATTLERS);
  }
  $packed=CanvasTextBatch::compact($text,$owners);expect(count($packed))->toBeLessThanOrEqual(StyledPresentationFrame::MAX_TEXT_LAYERS);
  foreach($packed as$layer){expect($owners->getOrigins($layer))->not->toBeEmpty();}
  expect(in_array($text[1],$packed,true))->toBeTrue();
});


it('charges every alternating treatment run to the shared group limit',function(){
  $images=[];$owners=new BattleCanvasOwners(compositionPalette());
  for($i=0;$i<129;$i++){$images[]=compositionImage('image-'.$i,$i);$owners->recordDrawing($images[$i],$i%2?BattleFieldColorRole::EFFECT:BattleFieldColorRole::BATTLERS);}
  $root=new PresentationCanvas(32,32,$images);$before=$root->toArray();
  expect(fn()=>BattleFieldColorComposition::compose($root,$owners))->toThrow(InvalidArgumentException::class,'at most 128')
    ->and($root->toArray())->toBe($before);
});

it('promotes a screen source required by a grouped marker without duplicating that source',function(){
  $body=compositionImage('body',1000);$marker=compositionOutline(2000);$owners=new BattleCanvasOwners(compositionPalette());
  $owners->recordDrawing($body,BattleFieldColorRole::SCREEN_UI);$owners->recordDrawing($marker,BattleFieldColorRole::BATTLERS);
  $root=new PresentationCanvas(32,32,[$body],[$marker]);$result=BattleFieldColorComposition::compose($root,$owners);
  expect(compositionLeaves($result))->toBe(['body','outline'])
    ->and(CanvasIndicatorReferences::resolve($result,$result->groups[1]->canvas,$result->groups[1]->canvas->indicators[0]))->toBe($body);
});

it('keeps unchanged root HUD objects through retained palette changes and release',function(){
  require_once __DIR__.'/../Support/Input/FakeRendererTransport.php';
  $caps=[\Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::GRAPHICAL_CANVAS,
    \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_GROUPS,
    \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_GROUP_COLOR,
    \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_REGION_BUDGET];
  $transport=new \Tests\Support\Input\FakeRendererTransport();$client=new \Ichiloto\Engine\Rendering\RendererClient($transport);
  $client->start(new \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig('Colour',__DIR__.'/../Fixtures/Renderer',
    protocol:\Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion::V2,requiredCapabilities:$caps));
  $transport->batches[]=[\Ichiloto\Engine\Rendering\Transport\RendererEvent::fromJson(json_encode(['protocol'=>2,'type'=>'ready','capabilities'=>$caps]))];$client->pump();
  $presenter=new \Ichiloto\Engine\Rendering\Presentation\RendererPresentation($client,new RendererGridConfig(2,1));
  $body=compositionImage('body',100);$hud=compositionImage('hud',1000);$root=new PresentationCanvas(32,32,[$body,$hud]);$changed=[];
  foreach([false,true,false]as$neutral){$owners=new BattleCanvasOwners(compositionPalette($neutral));
    $owners->recordDrawing($body,BattleFieldColorRole::BATTLERS);$owners->recordDrawing($hud,BattleFieldColorRole::SCREEN_UI);
    $frame=BattleFieldColorComposition::compose($root,$owners);$before=count($transport->sent);
    expect($presenter->presentCanvas($frame))->toBeTrue();$transport->acknowledgeLatestFrame($presenter);
    $changed[]=array_slice($transport->sent,$before);
  }
  foreach(array_slice($changed,1)as$messages){
    $wire=json_encode($messages,JSON_THROW_ON_ERROR);expect($wire)->not->toContain('"id":"hud"');
  }
});
