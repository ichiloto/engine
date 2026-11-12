<?php

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandRunner, BattleCommandTimeline, BattlePoseRole};
use Ichiloto\Engine\Battle\Resolution\{CombatRandomSource, ResolutionKind};
use Ichiloto\Engine\Cutscenes\Summons\{SummonCutsceneDefinition, SummonCutsceneCompiler};
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\{BasicSkill, SkillInvocation};
use Ichiloto\Engine\Util\Config\{ConfigStore, ProjectConfig, PlaySettings};
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

beforeEach(function () {
  $this->audioForHits = $this->createStub(AudioManager::class);
  $this->configBeforeHits = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  $this->root = createTestDirectory('recorded-hit-feedback-');
  \Tests\Support\Rendering\writeTestPng($this->root . '/contact.png', 8, 8);
  $this->data = ['fps' => 24, 'lengthFrames' => 28, 'restFrame' => 4,
    'effectTiming' => ['mode' => 'frame', 'frame' => 4], 'hitFeedbackFrames' => [4, 8, 12, 16],
    'tracks' => [
      ['id' => 'contacts', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'contact.png', 'anchor' => 'target',
        'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'duration' => 1], [4,8,12,16])],
      ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal', 'keyframes' => [['frame' => 0, 'duration' => 28, 'content' => '*']]],
    ]];
});

afterEach(function () {
  $this->configBeforeHits === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $this->configBeforeHits);
});

function createRecordedHitRunner($test, bool $activeTime, bool $graphical, int $hp, array $data, ResolutionKind $kind = ResolutionKind::TRUE_DAMAGE,
  int $accuracy = 0, int $recipients = 1, array $extraEffects = [], bool $selfTarget = false): array
{
  [$engine, $context, $screen, $actor, , $targets] = createTargetExecutionFixture($activeTime, $graphical, $test->audioForHits);
  $target = $selfTarget ? $actor : $targets[0]; $target->stats->currentHp = $hp;
  $selected = $selfTarget ? [$actor] : array_slice($targets, 0, $recipients);
  $random = new class implements CombatRandomSource {
    public function nextInt(int $minimum, int $maximum): int { return max($minimum, min($maximum, 95)); }
  };
  $action = new SkillBattleAction(new BasicSkill('Synthetic four contacts', '', '', 4, 0,
    invocation: new SkillInvocation(accuracy: $accuracy, repeat: 4), effects: [new HPDamageSkillEffect('10', variance: 0,
      resolutionKind: $kind), ...$extraEffects]), random: $random);
  $effect = (new EffectTimelineValidator($test->root))->compile('hit-feedback', $data, forBattle: true,
    presentation: $graphical ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL);
  $format = Closure::bind(function ($target, $hp, $mp, $result, $hit = null): array {
    return $this->buildStatChangePopupLines($target, $hp, $mp, $result, $hit);
  }, $engine->actionExecutionState, ActionExecutionState::class);
  $executions = 0; $events = [];
  $runner = new BattleCommandRunner($context, $actor, $selected, $action, '',
    new BattleTurnTimings(.01,.01,.01,.01,.01,.01,.01), null, $effect,
    function () use ($action, $actor, $selected, &$executions): void { $executions++; $action->execute($actor, $selected); },
    $format, static function (array $cue) use (&$events): void { if ($cue['type'] === 'commandResult') { $events[] = $cue; } });
  return [$runner, $actor, $target, $targets[1], $action, $screen, &$executions, &$events, $context];
}

it('presents each real hit once and preserves four visual contacts after a fatal result', function (bool $activeTime, bool $reduced, int $hp) {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  $fixture = createRecordedHitRunner($this, $activeTime, true, $hp, $this->data);
  [$runner, $actor, $target, $bystander, $action, $screen] = $fixture;
  $playback = $runner->playback; $plan = $playback->plan; $runner->begin();
  $frames = $plan->hitFeedbackFrames;
  $runner->update(($frames[0] - 1) / 120);
  expect($actor->stats->currentMp)->toBe(50)->and($screen->fieldWindow->results)->toBeEmpty();
  $expectedHits = min(4, (int)ceil($hp / 10));
  foreach ($frames as $index => $frame) {
    $runner->update(($frame - $playback->session->currentFrame) / 120);
    expect($fixture[6])->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($playback->getPresentedHp($target))->toBe(max(0, $hp - min($index + 1, $expectedHits) * 10))
      ->and($target->stats->currentHp)->toBe(max(0, $hp - 40))
      ->and($bystander->stats->currentHp)->toBe(100)
      ->and($screen->fieldWindow->results)->toHaveCount(min($index + 1, $expectedHits))
      ->and(array_column($playback->getActiveSegments(), 'layer'))->toContain('image')
      ->and($playback->presentationFailure)->toBeNull();
    if ($index < $expectedHits) {
      $hit = $action->lastResult->targets[0]->hits[$index];
      expect($fixture[7][$index]['payload']['hit'])->toBe($hit)
        ->and(array_column($screen->fieldWindow->results[$index][1], 'text'))->toBe($hit->postEffectHp <= 0 ? ['10','KO'] : ['10'])
        ->and($playback->getPoseRole($target))->toBe($hit->postEffectHp <= 0 ? BattlePoseRole::KNOCKOUT : BattlePoseRole::DAMAGE)
        ->and($playback->getPoseElapsedSeconds($target))->toBe(0.0);
    }
  }
  $snapshot = [$actor->stats->currentMp, $target->stats->currentHp, count($fixture[7]), $playback->session->currentFrame];
  foreach (array_reverse($frames) as $index => $frame) {
    $feedback = $playback->getRecordedHitFeedback($target, $frame);
    expect($feedback['hit'])->toBe($action->lastResult->targets[0]->hits[min(3 - $index, $expectedHits - 1)]);
  }
  expect([$actor->stats->currentMp,$target->stats->currentHp,count($fixture[7]),$playback->session->currentFrame])->toBe($snapshot)
    ->and($runner->result)->toBe($action->lastResult);
  if ($target->isKnockedOut) { expect($playback->getEnemyDefeatTreatment($target)['opacity'])->toBe(1.0); }
  $runner->update(100); $runner->update(100);
  expect($fixture[6])->toBe(1)->and($actor->stats->currentMp)->toBe(46)->and($screen->fieldWindow->results)->toHaveCount($expectedHits);
  $runner->dispose();
})->with([false,true])->with([false,true])->with([100,25,1]);

it('keeps Terminal aggregate results independent of graphical beat metadata', function (bool $activeTime) {
  unlink($this->root . '/contact.png');
  [$runner, $actor, $target, , $action, $screen] = createRecordedHitRunner($this, $activeTime, false, 25, $this->data);
  expect($runner->playback->plan->hitFeedbackFrames)->toBe([]);
  $runner->begin(); $runner->update(100);
  expect($screen->fieldWindow->results)->toHaveCount(1)
    ->and(array_column($screen->fieldWindow->results[0][1],'text'))->toBe(['30','KO'])
    ->and($action->lastResult->targets[0]->hits)->toHaveCount(3)->and($actor->stats->currentMp)->toBe(46);
  $runner->dispose();
})->with([false,true]);

it('abandons pending hit feedback without repeating or undoing gameplay', function (bool $afterImpact) {
  $fixture = createRecordedHitRunner($this, false, true, 100, $this->data);
  [$runner, $actor, $target, , , $screen] = $fixture; $runner->begin();
  $runner->update(($runner->playback->plan->impactFrame - ($afterImpact ? 0 : 1)) / 120);
  $count = count($screen->fieldWindow->results); $runner->dispose(); $runner->update(100);
  expect($screen->fieldWindow->results)->toHaveCount($count)
    ->and($fixture[6])->toBe($afterImpact ? 1 : 0)->and($actor->stats->currentMp)->toBe($afterImpact ? 46 : 50)
    ->and($target->stats->currentHp)->toBe($afterImpact ? 60 : 100)
    ->and($runner->playback->getRecordedHitFeedback($target))->toBeNull();
})->with([false,true]);

it('reports insufficient beats and retains the complete aggregate without replaying combat', function () {
  $data = $this->data; $data['hitFeedbackFrames'] = [4,8];
  $fixture = createRecordedHitRunner($this, false, true, 100, $data);
  [$runner, $actor, $target, , , $screen] = $fixture; $runner->begin(); $runner->update(100);
  expect($runner->playback->presentationFailure)->toBeInstanceOf(InvalidArgumentException::class)
    ->and($screen->fieldWindow->results)->toHaveCount(1)
    ->and(array_column($screen->fieldWindow->results[0][1],'text'))->toBe(['40'])
    ->and($fixture[6])->toBe(1)->and($target->stats->currentHp)->toBe(60)->and($actor->stats->currentMp)->toBe(46);
  $runner->dispose();
});

it('validates authored beat lists and refuses a first beat that differs from impact', function (mixed $frames) {
  $data = $this->data; $data['hitFeedbackFrames'] = $frames;
  expect(function () use ($data): void {
    $effect = (new EffectTimelineValidator($this->root))->compile('hit-feedback',$data,forBattle:true);
    new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1),target:$effect);
  })->toThrow(InvalidArgumentException::class);
})->with([[[]],[[4,4]],[[8,4]],[[4,28]],[['4']],[[3,8]],[[4,-1]],[null]]);

it('preserves flat and paired summon hit-feedback source data', function (bool $paired) {
  $data = $paired ? ['presentations'=>['graphical'=>$this->data,'terminal'=>['fps'=>24,'lengthFrames'=>3,
    'tracks'=>[['id'=>'rest','type'=>'glyph','keyframes'=>[['frame'=>0,'duration'=>3,'content'=>'*']]]]]]] : $this->data;
  $definition = SummonCutsceneDefinition::fromArrays(['id'=>'synthetic','name'=>'Synthetic','effectTiming'=>['mode'=>'explicit_frame','frame'=>4]],$data);
  $roundtrip = $definition->toTimelineArray();
  expect($paired ? $roundtrip['presentations']['graphical']['hitFeedbackFrames'] : $roundtrip['hitFeedbackFrames'])->toBe([4,8,12,16]);
  $compiled = (new SummonCutsceneCompiler(assetRoot:$this->root))->compile($definition);
  expect($compiled->defaults['hitFeedbackFrames'])->toBe([4,8,12,16]);
})->with([false,true]);


it('uses the recorded heal or miss for each beat', function (bool $healing) {
  $fixture = createRecordedHitRunner($this, false, true, 40, $this->data,
    $healing ? ResolutionKind::HEALING : ResolutionKind::TRUE_DAMAGE, $healing ? 0 : 1);
  [$runner, $actor, $target, , $action, $screen] = $fixture; $runner->begin();
  foreach ($runner->playback->plan->hitFeedbackFrames as $index => $frame) {
    $runner->update(($frame - $runner->playback->session->currentFrame) / 120);
    expect($screen->fieldWindow->results)->toHaveCount($index + 1)
      ->and(array_column($screen->fieldWindow->results[$index][1], 'text'))->toBe([$healing ? '+10' : 'MISS'])
      ->and($runner->playback->getPoseRole($target))->toBe($healing ? BattlePoseRole::HEAL : BattlePoseRole::IDLE)
      ->and($target->stats->currentHp)->toBe($healing ? 80 : 40);
  }
  expect($fixture[6])->toBe(1)->and($actor->stats->currentMp)->toBe(46)->and($action->lastResult->targets[0]->hits)->toHaveCount(4);
  $runner->dispose();
})->with([false,true]);

it('keeps same-name recipients separate and shows resource changes once', function (bool $activeTime) {
  $fixture = createRecordedHitRunner($this, $activeTime, true, 100, $this->data, recipients: 2,
    extraEffects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect('3', variance: 0)]);
  [$runner, $actor, $first, $second, $action, $screen] = $fixture;
  foreach ([$first,$second] as $target) { new ReflectionProperty($target,'name')->setValue($target,'Twin'); }
  $runner->begin(); $runner->update(100);
  expect($screen->fieldWindow->results)->toHaveCount(8)->and($fixture[6])->toBe(1)->and($actor->stats->currentMp)->toBe(46);
  foreach ([$first,$second] as $target) {
    $results = array_values(array_filter($screen->fieldWindow->results,static fn(array $row):bool=>$row[0] === $target));
    expect($results)->toHaveCount(4)->and($target->stats->currentHp)->toBe(60)->and($target->stats->currentMp)->toBe(38)
      ->and(array_column($results[0][1],'text'))->toBe(['10','-12 MP']);
    foreach (array_slice($results,1) as $row) { expect(array_column($row[1],'text'))->toBe(['10']); }
  }
  expect(array_column($fixture[7],'frame'))->toBe(array_merge(...array_map(static fn(int $frame):array=>[$frame,$frame],$runner->playback->plan->hitFeedbackFrames)));
  $runner->dispose();
})->with([false,true]);

it('traverses every recorded result when one update crosses the whole sequence', function () {
  $fixture = createRecordedHitRunner($this,false,true,25,$this->data);
  [$runner,$actor,$target,,$action,$screen]=$fixture; $runner->begin();$runner->update(100);
  expect(array_column($fixture[7],'frame'))->toBe(array_slice($runner->playback->plan->hitFeedbackFrames,0,3))
    ->and(array_map(static fn(array $row):array=>array_column($row[1],'text'),$screen->fieldWindow->results))->toBe([['10'],['10'],['10','KO']])
    ->and($fixture[6])->toBe(1)->and($actor->stats->currentMp)->toBe(46)->and($target->stats->currentHp)->toBe(0);
  $runner->dispose();
});

it('refuses hit feedback in a source lane or unsupported playback cadence', function (string $case) {
  $data=$this->data;
  if ($case==='loop') {$data['playback']='loop';}
  if ($case==='paced') {$data['cadence']='battle-phase';unset($data['fps']);}
  expect(function () use ($data,$case):void {
    $effect=(new EffectTimelineValidator($this->root))->compile('hit-feedback',$data,forBattle:true);
    if ($case==='source') {new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1),source:$effect);}
  })->toThrow(InvalidArgumentException::class);
})->with(['loop','paced','source']);

it('refuses mixed top-level feedback on a paired summon rather than losing it on save', function () {
  expect(fn()=>SummonCutsceneDefinition::fromArrays(['id'=>'synthetic','name'=>'Synthetic'],[
    'hitFeedbackFrames'=>[4,8], 'presentations'=>['graphical'=>$this->data,'terminal'=>$this->data],
  ]))->toThrow(InvalidArgumentException::class,'cannot be mixed');
});


it('projects party HUD HP from recorded contacts while leaving gameplay HP final', function () {
  $fixture=createRecordedHitRunner($this,false,true,100,$this->data,selfTarget:true);
  [$runner,$actor,$target,,,$screen]=$fixture;
  $status=new ReflectionClass(\Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($status,'battleScreen')->setValue($status,$screen);
  new ReflectionProperty($status,'characters')->setValue($status,[$actor]);
  new ReflectionProperty($status,'title')->setValue($status,'HP');
  new ReflectionProperty($status,'help')->setValue($status,'');
  $runner->begin();
  foreach ($runner->playback->plan->hitFeedbackFrames as $index=>$frame) {
    $runner->update(($frame-$runner->playback->session->currentFrame)/120);
    expect($actor->stats->currentHp)->toBe(60)
      ->and($status->presentationSnapshot()->rows[0]->currentHp)->toBe(100-($index+1)*10);
  }
  $runner->dispose();expect($status->presentationSnapshot()->rows[0]->currentHp)->toBe(60);
});


it('keeps the rendered enemy fully visible through all four contacts before defeat treatment', function () {
  $fixture=createRecordedHitRunner($this,false,true,25,$this->data);
  [$runner,$actor,$target,$other,,$screen,,,$context]=$fixture;
  foreach ([$target,$other] as $enemy) {
    foreach (['level'=>1,'image'=>['x'],'imagePath'=>''] as $key=>$value) {new ReflectionProperty($enemy,$key)->setValue($enemy,$value);}
  }
  $art=new \Ichiloto\Engine\Battle\Presentation\BattlerArtwork('contact.png',8,8,4,8);
  $catalog=new \Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog([
    'synthetic'=>new \Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition('Synthetic',
      new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('arena','contact.png',
        new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0,0,1440,840))),
  ],['Caster'=>$art,'Recipient'=>$art],['Enemy A'=>$art,'Enemy B'=>$art],
    ui:new \Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout(1440,840),defaultArena:'synthetic');
  $presentation=\Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation::prepare(
    new \Ichiloto\Engine\Scenes\Battle\BattleConfig($context->party,$context->troop),$catalog,$this->root);
  $runner->begin();
  foreach ($runner->playback->plan->hitFeedbackFrames as $frame) {
    $runner->update(($frame-$runner->playback->session->currentFrame)/120);
    $image=array_find($presentation->frame($screen->fieldWindow)->images,
      static fn($image):bool=>$image->id==='combatant-'.spl_object_id($target));
    expect($image)->not->toBeNull()->and($image->opacity)->toBe(1.0)->and($target->stats->currentHp)->toBe(0);
  }
  $runner->dispose();
});


it('preserves a combatant custom knockout policy when no recorded history owns its presentation', function () {
  $battler=new class('Synthetic',1,new \Ichiloto\Engine\Entities\Stats(currentHp:100,totalHp:100)) extends \Ichiloto\Engine\Entities\Character {
    public bool $isKnockedOut {get=>true;}
  };
  $playback=new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1)),$battler,[],BattlePoseRole::ATTACK,
    static fn()=>null,static fn()=>null);
  expect($playback->getPresentedHp($battler))->toBeNull()
    ->and($playback->isPresentedKnockedOut($battler))->toBeTrue()
    ->and($playback->getPoseRole($battler))->toBe(BattlePoseRole::KNOCKOUT);
  $playback->cancel();expect($playback->isPresentedKnockedOut($battler))->toBeTrue();
});
