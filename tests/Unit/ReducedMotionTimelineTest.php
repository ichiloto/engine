<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineValidator, EffectPresentation, CompiledEffectTimeline, EffectSegmentComposition, EffectMotionScope};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattlePoseRole};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Cutscenes\Summons\{SummonCutsceneTrack, SummonCutsceneDefinition, SummonCutsceneCompiler};

function reducedMotionTimelineData(): array
{
  return ['fps' => 24, 'lengthFrames' => 12, 'effectTiming' => ['mode' => 'frame', 'frame' => 9],
    'reducedMotionSamples' => [
      ['frame' => 0, 'duration' => 4, 'sourceFrame' => 1],
      ['frame' => 4, 'duration' => 3, 'sourceFrame' => 5],
      ['frame' => 7, 'duration' => 2, 'sourceFrame' => 8],
      ['frame' => 9, 'duration' => 2, 'sourceFrame' => 9],
      ['frame' => 11, 'duration' => 1, 'sourceFrame' => null]],
    'tracks' => [
      ['id' => 'shared', 'type' => 'glyph', 'presentation' => 'graphical', 'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => 'S']]],
      ['id' => 'reduced', 'type' => 'glyph', 'motion' => 'reduced', 'keyframes' => [
        ['frame' => 0, 'duration' => 4, 'content' => 'A'], ['frame' => 4, 'duration' => 3, 'content' => 'B'],
        ['frame' => 7, 'duration' => 2, 'content' => 'C'], ['frame' => 9, 'duration' => 3, 'content' => 'D']]],
      ['id' => 'normal', 'type' => 'glyph', 'motion' => 'normal', 'keyframes' => [
        ['frame' => 0, 'duration' => 12, 'content' => 'N', 'zIndex' => 10, 'payload' => ['clearBeforeDraw' => true]]]],
      ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal', 'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => 'T']]]],
    'cues' => [['id' => 'sound', 'frame' => 7, 'type' => 'playSound', 'payload' => ['sound' => 'synthetic']]]];
}

it('round trips the authored hold plan and motion variants without changing cues or Terminal presentation', function () {
  $data = reducedMotionTimelineData();
  foreach ($data['tracks'] as &$track) { $track = SummonCutsceneTrack::fromArray($track)->toArray(); }
  unset($track);
  $validator = new EffectTimelineValidator('');
  $compiled = CompiledEffectTimeline::fromArray($validator->compile('held', $data, forBattle: true)->toArray());
  expect($compiled->defaults['reducedMotionSamples'])->toBe($data['reducedMotionSamples'])
    ->and($compiled->cueSchedule[0]['frame'])->toBe(7)
    ->and(array_column($compiled->playbackSegments, 'motion'))->toContain('normal', 'reduced');
  $terminal = $validator->compile('terminal', $data, forBattle: true, presentation: EffectPresentation::TERMINAL);
  expect($terminal->defaults)->not->toHaveKey('reducedMotionSamples')
    ->and($terminal->playbackSegments)->toHaveCount(1)
    ->and($terminal->playbackSegments[0]['drawCommands'][0]['content'])->toBe('T');
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'held-summon', 'name' => 'Held',
    'effectTiming' => ['mode' => 'explicit_frame', 'frame' => 9]], $data);
  expect($definition->toTimelineArray()['reducedMotionSamples'])->toBe($data['reducedMotionSamples']);
  $summon = (new SummonCutsceneCompiler(assetRoot: ''))->compile($definition);
  expect($summon->defaults['reducedMotionSamples'])->toBe($data['reducedMotionSamples']);
});

it('rejects incomplete or ambiguous held plans and invalid motion scopes at admission', function (Closure $change) {
  $data = reducedMotionTimelineData(); $change($data);
  expect(fn() => (new EffectTimelineValidator(''))->compile('bad', $data, forBattle: true))->toThrow(InvalidArgumentException::class);
})->with([
  [fn(array &$d) => $d['reducedMotionSamples'] = []],
  [fn(array &$d) => $d['reducedMotionSamples'] = null],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['frame'] = 1],
  [fn(array &$d) => $d['reducedMotionSamples'][1]['frame'] = 3],
  [fn(array &$d) => $d['reducedMotionSamples'][1]['frame'] = 5],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['duration'] = 0],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['duration'] = 13],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['sourceFrame'] = -1],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['sourceFrame'] = 12],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['sourceFrame'] = '1'],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['sourceFrame'] = INF],
  [fn(array &$d) => $d['reducedMotionSamples'][0]['extra'] = true],
  [fn(array &$d) => $d['reducedMotionSamples'][4]['duration'] = 2],
  [fn(array &$d) => array_pop($d['reducedMotionSamples'])],
  [fn(array &$d) => $d['playback'] = 'loop'],
  [function(array &$d) { unset($d['fps']); $d['cadence'] = 'battle-phase'; }],
  [fn(array &$d) => $d['tracks'][1]['motion'] = 'sometimes'],
  [fn(array &$d) => $d['tracks'][1]['motion'] = false],
  [fn(array &$d) => $d['tracks'][1]['presentation'] = 'all'],
  [fn(array &$d) => $d['tracks'][1]['presentation'] = 'terminal'],
]);

it('selects held drawings and clear before composition without advancing cues or outcomes during inspection', function () {
  $data = reducedMotionTimelineData(); $validator = new EffectTimelineValidator('');
  $effect = $validator->compile('held', $data, forBattle: true);
  $terminal = $validator->compile('terminal', $data, forBattle: true, presentation: EffectPresentation::TERMINAL);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect, terminalTarget: $terminal);
  $actor = new Character('Actor',1,new Stats(currentHp:100,totalHp:100));
  $expected = [0 => 'A',3 => 'A',4 => 'B',6 => 'B',7 => 'C',8 => 'C',9 => 'D',10 => 'D',11 => null];
  $content = static fn(array $segments): array => array_column(array_merge(...array_column($segments,'drawCommands')),'content');
  foreach ([11,0,8,4,9,3,7,6,10,0] as $sample) {
    $seen = []; $hits = 0;
    $playback = new BattleCommandPlayback($plan,$actor,[$actor],BattlePoseRole::ATTACK,
      function() use (&$hits) { $hits++; },function(array $cue) use (&$seen) { $seen[]=$cue['id']; });
    $playback->begin(); $playback->update(($plan->phases['target']['start'] + $sample*5)/120);
    $before = [$playback->session->currentFrame,$hits,$seen];
    expect($content($playback->getActiveSegments(true)))->toBe($expected[$sample] === null ? [] : ['S',$expected[$sample]])
      ->and($content($playback->getActiveSegments(false)))->toBe(['N'])
      ->and($content($playback->getActiveSegments(true,true)))->toBe(['T'])
      ->and([$playback->session->currentFrame,$hits,$seen])->toBe($before)
      ->and(in_array('sound',$seen,true))->toBe($sample>=7)
      ->and($hits)->toBe($sample>=9 ? 1 : 0)->and($actor->stats->currentHp)->toBe(100);
    $playback->pause();$playback->update(10);
    expect($content($playback->getActiveSegments(true)))->toBe($expected[$sample] === null ? [] : ['S',$expected[$sample]]);
    $playback->resume();$playback->cancel();expect($playback->getActiveSegments(true))->toBe([]);
  }
});

it('filters unused motion variants before their clear commands can erase the selected picture', function () {
  $data = reducedMotionTimelineData();$compiled=(new EffectTimelineValidator(''))->compile('clear',$data,forBattle:true);
  $segments=array_values(array_filter($compiled->playbackSegments,static fn(array $s):bool=>$s['startFrame']<=1&&$s['endFrame']>=1));
  $content=static fn(array $s):array=>array_column(array_merge(...array_column($s,'drawCommands')),'content');
  expect($content(EffectSegmentComposition::compose($segments,EffectPresentation::GRAPHICAL,true)))->toBe(['S','A'])
    ->and($content(EffectSegmentComposition::compose($segments,EffectPresentation::GRAPHICAL,false)))->toBe(['N']);
});

it('keeps a shared Terminal glyph independent of graphical holds and clears', function () {
  $data = reducedMotionTimelineData();
  $data['tracks'][0]['presentation'] = 'all';
  $effect = (new EffectTimelineValidator(''))->compile('shared-terminal',$data,forBattle:true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1),target:$effect);
  $actor = new Character('Actor',1,new Stats(currentHp:100,totalHp:100));
  $p = new BattleCommandPlayback($plan,$actor,[$actor],BattlePoseRole::ATTACK,static fn()=>null,static fn()=>null);
  $p->begin();$p->update(($plan->phases['target']['start']+55)/120);
  expect($p->getActiveSegments(true))->toBe([])
    ->and(array_column(array_merge(...array_column($p->getActiveSegments(true,true),'drawCommands')),'content'))->toBe(['S']);
});

it('uses the same held selection in field effects without seeking future sound cues', function () {
  $data = reducedMotionTimelineData();unset($data['effectTiming']);
  $effect = (new EffectTimelineValidator(''))->compile('field-held',$data);
  $session = new \Ichiloto\Engine\Animations\Field\FieldEffectSession('held',
    \Ichiloto\Engine\Animations\Field\FieldEffectAnchor::fromArray(['cell'=>['x'=>2,'y'=>3]]),$effect);
  $update = $session->update(4/24);
  expect($update->crossedCues)->toBe([]);
  $content = static fn(array $segments):array=>array_column(array_merge(...array_column($segments,'drawCommands')),'content');
  expect($content($session->getActiveSegments(true,EffectPresentation::GRAPHICAL)))->toBe(['S','B'])
    ->and($content($session->getActiveSegments(false,EffectPresentation::GRAPHICAL)))->toBe(['N'])
    ->and($session->playback->currentFrame)->toBe(4);
  $session->playback->pause();$session->update(100);
  expect($session->playback->currentFrame)->toBe(4);
  $session->playback->resume();$session->update(7/24);
  expect($session->getActiveSegments(true,EffectPresentation::GRAPHICAL))->toBe([]);
});

it('plays an explicitly timed reduced summon on its original clock with one dispatch per cue', function () {
  $data=['fps'=>120,'lengthFrames'=>6,'reducedMotionSamples'=>[
    ['frame'=>0,'duration'=>3,'sourceFrame'=>0],['frame'=>3,'duration'=>2,'sourceFrame'=>3],
    ['frame'=>5,'duration'=>1,'sourceFrame'=>null]],'tracks'=>[
      ['id'=>'normal','type'=>'glyph','motion'=>'normal','keyframes'=>[['frame'=>0,'duration'=>6,'content'=>'N']]],
      ['id'=>'reduced','type'=>'glyph','motion'=>'reduced','keyframes'=>[['frame'=>0,'duration'=>3,'content'=>'R'],['frame'=>3,'duration'=>3,'content'=>'Q']]]],
    'cues'=>[['id'=>'sound','type'=>'playSound','frame'=>2,'payload'=>['sound'=>'synthetic']]]];
  $definition=SummonCutsceneDefinition::fromArrays(['id'=>'timed-summon','name'=>'Timed','effectTiming'=>['mode'=>'explicit_frame','frame'=>4]],$data);
  $compiled=(new SummonCutsceneCompiler(assetRoot:''))->compile($definition);
  $drawn=$cues=$clock=[];
  $timerClass=new ReflectionClass(\Ichiloto\Engine\Core\Timers::class);$statics=$timerClass->getStaticProperties();
  try {
    \Ichiloto\Engine\Core\Timers::clear();\Ichiloto\Engine\Core\Timers::setFrameTick(null);
    (new \Ichiloto\Engine\Cutscenes\Summons\SummonCutscenePlayer())->play($compiled,
      function(int $frame,array $segments) use (&$drawn) { $drawn[$frame]=array_column(array_merge(...array_column($segments,'drawCommands')),'content'); },
      function(array $cue,int $frame) use (&$cues) { $cues[]=[$cue['id'],$frame]; },
      function(int $frame) use (&$clock) { $clock[]=$frame; },reducedMotion:true);
  } finally { foreach($statics as $key=>$value) { $timerClass->getProperty($key)->setValue(null,$value); } }
  expect($drawn)->toBe([['R'],['R'],['R'],['Q'],['Q'],[]])->and($clock)->toBe([0,1,2,3,4,5])
    ->and(array_values(array_filter($cues,static fn(array $cue):bool=>$cue[0]==='sound')))->toBe([['sound',2]]);
});


it('selects result-effect variants before clear composition on their own original clock', function () {
  $effect = (new EffectTimelineValidator(''))->compile('result-held', reducedMotionTimelineData(), forBattle: true);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1));
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->begin(); $playback->startResultEffect($actor, 0, $effect, EffectPresentation::GRAPHICAL);
  $playback->update(4/24);
  $content = static fn(array $segments): array => array_column(array_merge(...array_column($segments, 'drawCommands')), 'content');
  expect($content($playback->getActiveSegments(true)))->toBe(['S', 'B']);
  $playback->update(7/24);
  expect($playback->getActiveSegments(true))->toBe([])->and($actor->stats->currentHp)->toBe(100);
});
