<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineLibrary, EffectPresentation, CompiledEffectTimeline};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleFieldColor, BattleFieldColorState, BattleCommandTimeline, BattleCommandPlayback, BattlePoseRole};
use Ichiloto\Engine\Cutscenes\Summons\{SummonCutsceneDefinition, SummonCutsceneCompiler};
use Ichiloto\Engine\Entities\{Character, Stats};
use function Tests\Support\Rendering\writeTestPng;
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function fieldColorPalette(): array {
  return ['field' => ['matrix' => [[-1,0,0],[0,-1,0],[0,0,-1]], 'offset' => [1,1,1]],
    'battlers' => ['matrix' => [[0,0,0],[0,0,0],[0,0,0]], 'offset' => [0,0,0]],
    'effects' => [['track' => '0', 'transform' => ['matrix' => [[0,0,0],[0,0,0],[0,0,0]], 'offset' => [1,1,1]]]]];
}
function fieldColorSource(): array {
  return ['fps' => 24, 'lengthFrames' => 12, 'restFrame' => 0, 'effectTiming' => ['mode' => 'frame', 'frame' => 10],
    'tracks' => [['id' => 'palette', 'type' => 'field-color', 'motion' => 'normal', 'keyframes' => [
      ['frame' => 0, 'duration' => 3, 'payload' => fieldColorPalette()],
      ['frame' => 3, 'duration' => 2, 'payload' => ['field' => null, 'battlers' => null, 'effects' => []]],
      ['frame' => 6, 'duration' => 2, 'payload' => fieldColorPalette()],
    ]], ['id' => '0', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'color.png',
      'keyframes' => [['frame' => 0, 'duration' => 12]]]]];
}

beforeEach(function () {
  $this->colorRoot = getTestTemporaryDirectory() . '/field-color-' . bin2hex(random_bytes(4));mkdir($this->colorRoot);
  writeTestPng($this->colorRoot . '/color.png', 8, 8);$this->colorLibrary = new EffectTimelineLibrary($this->colorRoot);
});
afterEach(function () { unlink($this->colorRoot . '/color.png');rmdir($this->colorRoot); });

it('compiles a complete palette with forward drawing references and retains explicit neutral samples', function () {
  $data=fieldColorSource();$before=serialize($data);$effect=$this->colorLibrary->compile('color',$data,true);
  $segments=array_values(array_filter($effect->playbackSegments, static fn($s)=>$s['layer']==='field-color'));
  $state=BattleFieldColorState::fromArray($segments[0]['drawCommands'][0]['payload']);
  expect($state->field->transformRgb(.2,.3,.4))->toBe([.8,.7,.6])
    ->and($state->battlers->transformRgb(.2,.3,.4))->toBe([0.0,0.0,0.0])
    ->and($state->effects['0']->transformRgb(.2,.3,.4))->toBe([1.0,1.0,1.0])
    ->and($segments[1]['drawCommands'][0]['payload'])->toBe(['field'=>null,'battlers'=>null,'effects'=>[]])
    ->and(serialize($data))->toBe($before)
    ->and(CompiledEffectTimeline::fromArray($effect->toArray())->toArray())->toBe($effect->toArray());
});

it('refuses invalid palette payloads before source admission', function (Closure $change) {
  $source=fieldColorSource();$change($source);
  expect(fn()=>$this->colorLibrary->compile('invalid-color',$source,true))->toThrow(InvalidArgumentException::class);
})->with([
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['field']['offset'][0]=2],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['field']['matrix'][0][0]='-1'],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['field']['matrix'][0][0]=NAN],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['battlers']='black'],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['extra']=true],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['effects']=[['track'=>'missing','transform'=>null]]],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['effects']=[['track'=>'palette','transform'=>null]]],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['effects']=[['track'=>'0','transform'=>null],['track'=>'0','transform'=>null]]],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['effects']=['0'=>null]],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']['effects']=[['track'=>0,'transform'=>null]]],
  [function(array &$d){unset($d['tracks'][0]['keyframes'][0]['payload']['field']);}],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['duration']=0],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['frame']='0'],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][1]['frame']=1],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['visible']=1],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['zIndex']=1],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]['payload']=null],
  [fn(array &$d)=>$d['tracks'][0]['keyframes'][0]='not a descriptor'],
]);

it('allows exclusive accessibility owners and refuses overlapping controllers for either mode', function (bool $overlap) {
  $data=fieldColorSource();$second=$data['tracks'][0];$second['id']='alternate';$second['motion']=$overlap?'all':'reduced';$data['tracks'][]=$second;
  if($overlap){expect(fn()=>$this->colorLibrary->compile('overlap',$data,true))->toThrow(InvalidArgumentException::class,'Only one');}
  else{expect($this->colorLibrary->compile('exclusive',$data,true)->playbackSegments)->toHaveCount(7);}
})->with([false,true]);

it('keeps graphical colour resources independent of Terminal and refuses ambient or repeating ownership', function () {
  $data=fieldColorSource();$data['tracks'][1]['asset']='missing.png';
  expect($this->colorLibrary->compile('terminal',$data,true,EffectPresentation::TERMINAL)->playbackSegments)->toBe([])
    ->and(fn()=>$this->colorLibrary->compile('ambient',$data))->toThrow(InvalidArgumentException::class);
  $data=fieldColorSource();$data['playback']='loop';expect(fn()=>$this->colorLibrary->compile('loop',$data,true))->toThrow(InvalidArgumentException::class);
});

it('preserves structured field-colour source through flat and paired summon authoring', function (bool $paired) {
  $graphical=fieldColorSource();$terminal=['fps'=>24,'lengthFrames'=>12,'tracks'=>[['id'=>'text','type'=>'glyph','keyframes'=>[['frame'=>0,'duration'=>12,'content'=>'*']]]]];
  $data=$paired?['presentations'=>['graphical'=>$graphical,'terminal'=>$terminal]]:$graphical;
  $definition=SummonCutsceneDefinition::fromArrays(['id'=>'color-summon','name'=>'Synthetic'],$data);$export=$definition->toTimelineArray();
  $out=$paired?$export['presentations']['graphical']:$export;
  expect($out['tracks'][0]['keyframes'])->toBe($graphical['tracks'][0]['keyframes']);
  $compiled=(new SummonCutsceneCompiler(assetRoot:$this->colorRoot))->compile($definition);
  expect(array_filter($compiled->playbackSegments,static fn($s)=>$s['layer']==='field-color'))->toHaveCount(3);
  if($paired){expect($export['presentations']['terminal'])->toBe($terminal);}
})->with([false,true]);

it('refuses legacy coercion of malformed field-colour frames in both summon source forms', function (bool $paired) {
  $source=fieldColorSource();$source['tracks'][0]['keyframes'][0]['frame']='oops';$data=$paired?['presentations'=>['graphical'=>$source,'terminal'=>['fps'=>24,'lengthFrames'=>12,'tracks'=>[]]]]:$source;
  $definition=SummonCutsceneDefinition::fromArrays(['id'=>'invalid','name'=>'Synthetic'],$data);
  expect(fn()=>(new SummonCutsceneCompiler(assetRoot:$this->colorRoot))->compile($definition))->toThrow(InvalidArgumentException::class);
})->with([false,true]);

it('samples the real command clock independently of visual clear and preserves pause and one outcome', function () {
  $data=fieldColorSource();$data['tracks'][]=['id'=>'clear','type'=>'glyph','presentation'=>'graphical','keyframes'=>[['frame'=>0,'duration'=>12,'content'=>'.','payload'=>['clearBeforeDraw'=>true]]]];
  $effect=$this->colorLibrary->compile('color',$data,true);$actor=new Character('Same name',1,new Stats(currentHp:100,totalHp:100));$target=new Character('Same name',1,new Stats(currentHp:100,totalHp:100));$hits=0;
  $plan=new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1),target:$effect);
  $playback=new BattleCommandPlayback($plan,$actor,[$target],BattlePoseRole::ATTACK,function()use(&$hits){$hits++;},static fn()=>null);$playback->begin();$start=$plan->phases['target']['start'];$playback->update($start/120);
  $state=BattleFieldColor::getState($playback,false);expect($state)->not->toBeNull()->and(array_keys($state->effects))->toBe(['target-0'])
    ->and(BattleFieldColor::getState($playback,true))->toBeNull()->and($hits)->toBe(0);
  $playback->pause();$playback->update(1);expect(BattleFieldColor::getState($playback,false)->toArray())->toBe($state->toArray());$playback->resume();
  $playback->update(15/120);$neutral=BattleFieldColor::getState($playback,false);expect($neutral)->not->toBeNull()->and($neutral->field)->toBeNull()->and($neutral->effects)->toBe([]);
  $playback->update(10/120);expect(BattleFieldColor::getState($playback,false))->toBeNull();
  $playback->update(5/120);expect(BattleFieldColor::getState($playback,false)->field)->not->toBeNull();
  $playback->update(1);expect($hits)->toBe(1)->and(BattleFieldColor::getState($playback,false))->toBeNull()
    ->and($actor->stats->currentHp)->toBe(100)->and($target->stats->currentHp)->toBe(100);
  $playback->cancel();expect(BattleFieldColor::getState($playback,false))->toBeNull()->and($hits)->toBe(1);
});

it('keeps random access sampling pure and refuses a source lane owning field colour', function () {
  $effect=$this->colorLibrary->compile('color',fieldColorSource(),true);$timings=new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1);
  expect(fn()=>new BattleCommandTimeline($timings,source:$effect))->toThrow(InvalidArgumentException::class,'target effect timeline');
  $plan=new BattleCommandTimeline($timings,target:$effect);$actor=new Character('Actor',1,new Stats());$start=$plan->phases['target']['start'];$states=[];
  foreach([7,0,5,3,1,6,0] as $frame){$p=new BattleCommandPlayback($plan,$actor,[$actor],BattlePoseRole::ATTACK,static fn()=>null,static fn()=>null);$p->begin();$p->update(($start+5*$frame)/120);$states[$frame]=BattleFieldColor::getState($p,false)?->toArray();}
  expect($states[0])->toBe($states[1])->toBe($states[6])->toBe($states[7])->and($states[5])->toBeNull()
    ->and($states[3])->toBe(['field'=>null,'battlers'=>null,'effects'=>[]]);
});
